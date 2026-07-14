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

use Fraym\Enum\{BuiltInRights, PasswordHashVersion};
use Fraym\Helper\{AuthHelper, CookieHelper, DataHelper, LocaleHelper, ResponseHelper};
use Fraym\Interface\CurrentUser as CurrentUserInterface;

final class CurrentUser implements CurrentUserInterface
{
    /** Id пользователя */
    private int|string|null $id = null;

    /** Sid пользователя */
    private ?int $sid = null;

    /** Права пользователя */
    private array $allRights = [];

    /** Количество элементов на одной странице по умолчанию */
    private int $bazeCount = 50;

    /** Отключение по умолчанию галочки перенаправления на предыдущую страницу при сохранении объекта */
    private bool $blockSaveReferer = false;

    /** Отключение по умолчанию перенаправления на последнюю посещенную перед уходом с сайта страницу */
    private bool $blockAutoRedirect = false;

    /** Массив настоящих данных пользователя-администратора при переключении на другой профиль */
    private ?array $adminData = null;

    /** Аутентификация прошла через Authorization: Bearer (внешний API), а не cookie — CSRF пропускается */
    private bool $authenticatedViaBearer = false;

    /** Создание или получение текущего пользователя в константу. По умолчанию: CURRENT_USER */
    public static function getInstance(string $constName = 'CURRENT_USER'): self
    {
        if (defined($constName)) {
            return constant($constName);
        } else {
            return self::forceCreate();
        }
    }

    /** Принудительное создание */
    public static function forceCreate(): self
    {
        return new self();
    }

    /** Проверка, запрещено ли пользователю видеть данные по профилям (своему и чужим) */
    public function blockedProfileEdit(): bool
    {
        if ($this->isAdmin()) {
            return false;
        }

        return $this->checkAllRights($_ENV['BLOCKED_PROFILE_EDIT_RIGHT']);
    }

    /** Проверка залогиненности пользователя */
    public function isLogged(): bool
    {
        return $this->id() > 0;
    }

    /** Проверка, забанен ли пользователь перманентно */
    public function isBanned(): bool
    {
        return $this->checkAllRights(BuiltInRights::BANNED->value);
    }

    /** Проверка, является ли пользователь администратором */
    public function isAdmin(bool $checkAdminDataAllRights = false): bool
    {
        return $this->checkAllRights(BuiltInRights::ADMIN->value) ||
            (
                $checkAdminDataAllRights &&
                (CURRENT_USER->getAdminData()['rights'] ?? false) &&
                DataHelper::inArrayAny([BuiltInRights::ADMIN->value, '1'], CURRENT_USER->getAdminData()['rights'])
            );
    }

    /** Проверка прав */
    public function checkAllRights(string $right_id): bool
    {
        return in_array($right_id, $this->allRights);
    }

    /** Разлогинивание пользователя */
    public function authLogout(?string $byeMessage = null): void
    {
        CookieHelper::deleteAllCookies();

        if (!is_null($byeMessage)) {
            ResponseHelper::error($byeMessage);
        }

        ResponseHelper::redirect(ABSOLUTE_PATH . '/');
    }

    /** Логин пользователя */
    public function auth(): void
    {
        $LOCALE = LocaleHelper::getLocale(['fraym', 'basefunc']);

        /** JWT проверяем из httpOnly cookie (браузерный SPA), затем из Authorization: Bearer (внешние API).
         * validateAuthToken проверяет подпись/alg/exp — payload доверенный, но несёт лишь id/sid,
         * поэтому rights/bazecount/block_* берём свежими из БД (отзыв прав действует немедленно). */
        $authTokenFromCookie = AuthHelper::getAuthTokenFromCookie();
        $authToken = $authTokenFromCookie ?? AuthHelper::getAuthTokenFromBearer();
        $jwtTokenPayload = is_null($authToken) ? null : AuthHelper::validateAuthToken($authToken);

        if (!is_null($jwtTokenPayload)) {
            $loginData = DB->select('user', ['id' => $jwtTokenPayload['id'] ?? null], true);

            if ($loginData) {
                CURRENT_USER->authSetUserData($loginData);

                /** CSRF пропускается только для аутентификации через Bearer (внешние API);
                 * cookie-аутентифицированный SPA обязан слать X-CSRF-Token. */
                if (is_null($authTokenFromCookie)) {
                    CURRENT_USER->setAuthenticatedViaBearer(true);
                }
            }
        } else {
            /** Если токена нет, то проверяем наличие cookie */
            $refreshToken = AuthHelper::getRefreshTokenCookie();

            if (!is_null($refreshToken)) {
                if (!REQUEST_TYPE->isDynamicRequest()) {
                    /** Если это не динамический запрос (т.е. просто загружается страница по адресу) */
                    $loginData = DB->select('user', ['refresh_token' => $refreshToken], true);

                    if ($loginData) {
                        if (($loginData['refresh_token_exp'] ?? false) && strtotime($loginData['refresh_token_exp']) > time()) {
                            CURRENT_USER->authSetUserData($loginData);
                            /** Обновляем JWT-cookie на полной загрузке, чтобы последующие XHR были авторизованы */
                            AuthHelper::setAuthTokenCookie(AuthHelper::generateAuthToken());
                        } else {
                            /** Cookie есть, но он просроченный, обновляем его */
                            AuthHelper::generateAndSaveRefreshToken();
                        }
                    }
                } else {
                    /** Это динамический запрос и куки есть, но токена нет, выдаем 401 */
                    ResponseHelper::response401();
                }
            }
        }

        /** Если ничего не подошло, но действие = login, то проверяем логин и пароль */
        if ('login' === ACTION && isset($_REQUEST['password'])) {
            if (!AuthHelper::validatePreAuthCsrfToken()) {
                ResponseHelper::responseOneBlock('error', $LOCALE['wrong_login_or_password']);
            }

            $loginData = $this->checkPassword();

            if ($loginData) {
                CURRENT_USER->authSetUserData($loginData);
                AuthHelper::generateAndSaveRefreshToken();
                AuthHelper::setAuthTokenCookie(AuthHelper::generateAuthToken());
            } else {
                ResponseHelper::responseOneBlock('error', $LOCALE['wrong_login_or_password']);
            }
        }

        if (CURRENT_USER->isLogged()) {
            /** Переключение администратора на другого пользователя */
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

    /** Выставляем набор данных пользователя при логине */
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

    private function checkPassword(): array|false
    {
        $loginData = DB->select(
            'user',
            [
                'login' => $_REQUEST['login'],
            ],
            true,
        );

        if ($loginData === false || !($loginData['password_hashed'] ?? false)) {
            return false;
        }

        $hashedPassword = AuthHelper::addProjectHashWord($_REQUEST['password']);

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
