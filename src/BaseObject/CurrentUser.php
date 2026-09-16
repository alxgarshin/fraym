<?php

/*
 * This file is part of the Fraym package.
 *
 * (c) Alex Garshin <alxgarshin@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Fraym\BaseObject;

use Fraym\Enum\{BuiltInRights, PasswordHashVersion, ResponseErrorCodeEnum};
use Fraym\Helper\{AuthHelper, CookieHelper, DataHelper, LocaleHelper, ResponseHelper};
use Fraym\Interface\CurrentUser as CurrentUserInterface;
use Fraym\Service\AuthTokenService;

final class CurrentUser implements CurrentUserInterface
{
    /** User id */
    private int|string|null $id = null;

    /** User sid */
    private ?int $sid = null;

    /** User rights */
    private array $allRights = [];

    /** Default number of items per page */
    private int $bazeCount = 50;

    /** Disables by default the checkbox for redirecting to the previous page after saving an object */
    private bool $blockSaveReferer = false;

    /** Disables by default the redirect to the last page visited before leaving the site */
    private bool $blockAutoRedirect = false;

    /** Real data of the administrator user while switched to another profile */
    private ?array $adminData = null;

    /** Authentication went through Authorization: Bearer (external API), not a cookie — CSRF is skipped */
    private bool $authenticatedViaBearer = false;

    /** Create or get the current user into a constant. Default: CURRENT_USER */
    public static function getInstance(string $constName = 'CURRENT_USER'): self
    {
        if (defined($constName)) {
            return constant($constName);
        } else {
            return self::forceCreate();
        }
    }

    /** Forced creation */
    public static function forceCreate(): self
    {
        return new self();
    }

    /** Check whether the user is forbidden to see profile data (their own and others') */
    public function blockedProfileEdit(): bool
    {
        if ($this->isAdmin()) {
            return false;
        }

        return $this->checkAllRights($_ENV['BLOCKED_PROFILE_EDIT_RIGHT']);
    }

    /** Check whether the user is logged in */
    public function isLogged(): bool
    {
        return $this->id() > 0;
    }

    /** Check whether the user is permanently banned */
    public function isBanned(): bool
    {
        return $this->checkAllRights(BuiltInRights::BANNED->value);
    }

    /** Check whether the user is an administrator */
    public function isAdmin(bool $checkAdminDataAllRights = false): bool
    {
        return $this->checkAllRights(BuiltInRights::ADMIN->value) ||
            (
                $checkAdminDataAllRights &&
                (CURRENT_USER->getAdminData()['rights'] ?? false) &&
                DataHelper::inArrayAny([BuiltInRights::ADMIN->value, '1'], CURRENT_USER->getAdminData()['rights'])
            );
    }

    /** Rights check */
    public function checkAllRights(string $right_id): bool
    {
        return in_array($right_id, $this->allRights);
    }

    /** Log the user out on the current device: tokens of their other devices remain valid */
    public function authLogout(?string $byeMessage = null): void
    {
        $refreshToken = AuthHelper::getRefreshTokenCookie();

        if (!is_null($refreshToken)) {
            AuthTokenService::revokeRefreshToken($refreshToken);
        }

        CookieHelper::deleteAllCookies();

        if (!is_null($byeMessage)) {
            ResponseHelper::error($byeMessage);
        }

        ResponseHelper::redirect(ABSOLUTE_PATH . '/');
    }

    /** User login */
    public function auth(): void
    {
        $LOCALE = LocaleHelper::getLocale(['fraym', 'basefunc']);

        /** JWT is checked from the httpOnly cookie (browser SPA), then from Authorization: Bearer (external APIs).
         * validateAuthToken checks signature/alg/exp — the payload is trusted but carries only id/sid,
         * so rights/bazecount/block_* are taken fresh from the DB (revoking rights takes effect immediately). */
        $authTokenFromCookie = AuthHelper::getAuthTokenFromCookie();
        $authToken = $authTokenFromCookie ?? AuthHelper::getAuthTokenFromBearer();
        $jwtTokenPayload = is_null($authToken) ? null : AuthHelper::validateAuthToken($authToken);

        if (!is_null($jwtTokenPayload)) {
            $loginData = DB->select('user', ['id' => $jwtTokenPayload['id'] ?? null], true);

            if ($loginData) {
                CURRENT_USER->authSetUserData($loginData);

                /** CSRF is skipped only for authentication via Bearer (external APIs);
                 * a cookie-authenticated SPA must send X-CSRF-Token. */
                if (is_null($authTokenFromCookie)) {
                    CURRENT_USER->setAuthenticatedViaBearer(true);
                }
            }
        } else {
            /** If there is no token, check for the cookie */
            $refreshToken = AuthHelper::getRefreshTokenCookie();

            if (!is_null($refreshToken)) {
                if (!REQUEST_TYPE->isDynamicRequest()) {
                    /** If this is not a dynamic request (i.e. the page is simply loaded by its address) */
                    $loginData = AuthTokenService::findUserByRefreshToken($refreshToken);

                    if (!is_null($loginData)) {
                        CURRENT_USER->authSetUserData($loginData);
                        /** Refresh the JWT cookie on full page load so that subsequent XHRs are authorized */
                        AuthHelper::setAuthTokenCookie(AuthHelper::generateAuthToken());
                    } else {
                        /** The token is expired or revoked: a new one is issued only by password */
                        AuthHelper::removeRefreshTokenCookie();
                    }
                } else {
                    /** This is a dynamic request with cookies but no token, return 401 */
                    ResponseHelper::response401();
                }
            }
        }

        /** If nothing matched but the action is login, check the login and password */
        if ('login' === ACTION && isset($_REQUEST['password'])) {
            if (!AuthHelper::validatePreAuthCsrfToken()) {
                ResponseHelper::responseOneBlock('error', $LOCALE['wrong_login_or_password'], [], ResponseErrorCodeEnum::forbidden);
            }

            $login = is_string($_REQUEST['login'] ?? null) ? $_REQUEST['login'] : '';
            $retryAfter = AuthTokenService::getRetryAfter($login);

            if (!is_null($retryAfter)) {
                header('Retry-After: ' . $retryAfter);
                ResponseHelper::responseOneBlock('error', $LOCALE['too_many_auth_attempts'], [], ResponseErrorCodeEnum::rateLimited);
            }

            $loginData = $this->checkPassword();

            if ($loginData) {
                AuthTokenService::clearAttempts($login);
                CURRENT_USER->authSetUserData($loginData);
                AuthHelper::generateAndSaveRefreshToken();
                AuthHelper::setAuthTokenCookie(AuthHelper::generateAuthToken());
            } else {
                AuthTokenService::registerFailedAttempt($login);
                ResponseHelper::responseOneBlock('error', $LOCALE['wrong_login_or_password'], [], ResponseErrorCodeEnum::unauthorized);
            }
        }

        if (CURRENT_USER->isLogged()) {
            /** Administrator switching to another user */
            if (CURRENT_USER->isAdmin(true)) {
                $admUserRequest = $_REQUEST['adm_user'] ?? null;
                $admUser = (int) ($admUserRequest ?? CookieHelper::getCookie('admUser'));

                if ($admUser > 0) {
                    if (CURRENT_USER->id() === $admUser) {
                        if ($admUserRequest) {
                            CookieHelper::batchSetCookie(['admUser' => (string) CURRENT_USER->id()]);
                            ResponseHelper::success($LOCALE['switched_to_your_profile']);
                        } else {
                            CookieHelper::batchDeleteCookie(['admUser']);
                        }
                    } else {
                        $userData = DB->select(
                            'user',
                            [
                                'id' => $admUser,
                            ],
                            true,
                        );

                        if ($userData) {
                            CURRENT_USER->setAdminData([
                                'id' => CURRENT_USER->id(),
                                'sid' => CURRENT_USER->sid(),
                                'rights' => CURRENT_USER->getAllRights(),
                                'bazecount' => CURRENT_USER->getBazeCount(),
                                'block_save_referer' => CURRENT_USER->getBlockSaveReferer(),
                                'block_auto_redirect' => CURRENT_USER->getBlockAutoRedirect(),
                            ]);
                            CURRENT_USER->authSetUserData($userData);

                            if (!is_null($admUserRequest)) {
                                CookieHelper::batchSetCookie(['admUser' => (string) $admUser]);
                                ResponseHelper::success(sprintf($LOCALE['switched_to_other_user'], $admUser));
                            }
                        }
                    }
                }
            }

            if ('login' === ACTION) {
                $redirect_path = ResponseHelper::createRedirect();

                ResponseHelper::redirect($redirect_path ?? ABSOLUTE_PATH);
            }
        }
    }

    /** Set the user data on login */
    public function authSetUserData(array $userData): void
    {
        CURRENT_USER->setId($userData['id'])
            ->setSid($userData['sid'])
            ->setAllRights($userData['rights'])
            ->setBazeCount($userData['bazecount'] ?? 50)
            ->setBlockSaveReferer($userData['block_save_referer'] === '1')
            ->setBlockAutoRedirect($userData['block_auto_redirect'] === '1');
    }

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function setId(int|string|null $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function id(): int|string|null
    {
        return $this->id;
    }

    public function getSid(): ?int
    {
        return $this->sid;
    }

    public function setSid(?int $sid): static
    {
        $this->sid = $sid;

        return $this;
    }

    public function sid(): ?int
    {
        return $this->sid;
    }

    public function getAllRights(): array
    {
        return $this->allRights;
    }

    public function setAllRights(string|array|null $allRights): static
    {
        $allRights = is_string($allRights) ? DataHelper::multiselectToArray($allRights) : $allRights;
        $allRights = is_null($allRights) ? [] : $allRights;
        $this->allRights = $allRights;

        return $this;
    }

    public function getBazeCount(): int
    {
        return $this->bazeCount;
    }

    public function setBazeCount(int $bazeCount): static
    {
        $this->bazeCount = $bazeCount;

        return $this;
    }

    public function getBlockSaveReferer(): bool
    {
        return $this->blockSaveReferer;
    }

    public function setBlockSaveReferer(bool $blockSaveReferer): static
    {
        $this->blockSaveReferer = $blockSaveReferer;

        return $this;
    }

    public function getBlockAutoRedirect(): bool
    {
        return $this->blockAutoRedirect;
    }

    public function setBlockAutoRedirect(bool $blockAutoRedirect): static
    {
        $this->blockAutoRedirect = $blockAutoRedirect;

        return $this;
    }

    public function getAdminData(): ?array
    {
        return $this->adminData;
    }

    public function setAdminData(array $adminData): static
    {
        $this->adminData = $adminData;

        return $this;
    }

    public function isAuthenticatedViaBearer(): bool
    {
        return $this->authenticatedViaBearer;
    }

    public function setAuthenticatedViaBearer(bool $authenticatedViaBearer): static
    {
        $this->authenticatedViaBearer = $authenticatedViaBearer;

        return $this;
    }

    public function authenticateForApi(string $login, string $password): ?array
    {
        $loginData = $this->checkPassword($login, $password);

        return $loginData === false ? null : $loginData;
    }

    private function checkPassword(?string $login = null, ?string $password = null): array|false
    {
        $login = $login ?? $_REQUEST['login'];
        $password = $password ?? $_REQUEST['password'];

        $loginData = DB->select(
            'user',
            [
                'login' => $login,
            ],
            true,
        );

        if ($loginData === false || !($loginData['password_hashed'] ?? false)) {
            return false;
        }

        $hashedPassword = AuthHelper::addProjectHashWord($password);

        if (($loginData['hash_version'] ?? false) && $loginData['hash_version'] === PasswordHashVersion::WRAPPED_V1->value) {
            if (!password_verify(md5($hashedPassword), $loginData['password_hashed'])) {
                return false;
            }

            $final = AuthHelper::hashPassword($hashedPassword, false);

            DB->update(
                tableName: 'user',
                data: [
                    'password_hashed' => $final,
                    'hash_version'    => PasswordHashVersion::FINAL_V2->value,
                ],
                criteria: [
                    'id' => $loginData['id'],
                ],
            );
        } elseif (!password_verify($hashedPassword, $loginData['password_hashed'])) {
            return false;
        }

        return $loginData;
    }
}
