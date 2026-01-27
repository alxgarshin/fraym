<?php

declare(strict_types=1);

namespace App\CMSVC\User;

use App\Helper\{DesignHelper, UniversalHelper};
use Fraym\BaseObject\{BaseService, Controller};
use Fraym\Enum\OperandEnum;
use Fraym\Helper\{AuthHelper, EmailHelper, FileHelper, LocaleHelper, RightsHelper};
use Identicon\Identicon;

/** @extends BaseService<UserModel> */
#[Controller(UserController::class)]
class UserService extends BaseService
{
    private array $usersOnlineStatuses = [];

    /** Часто используемый вариант вывода имени пользователя */
    public function showName(?UserModel $userModel, bool $link = false): string
    {
        return $this->showNameExtended($userModel, true, $link, '', false, false, true);
    }

    /** Часто используемый вариант вывода имени пользователя вместе с его id */
    public function showNameWithId(?UserModel $userModel, bool $link = false): string
    {
        return $this->showNameExtended($userModel, false, $link, '', false, true, true);
    }

    /** Трансформация Ф.И.О. пользователя в ссылку и удобно читаемый вид */
    public function showNameExtended(
        ?UserModel $userModel,
        bool $showId = false,
        bool $link = false,
        string $class = '',
        bool $shortName = false,
        bool $addIdInAnyCase = false,
        bool $addNickname = false,
    ): string {
        $LOCALE = LocaleHelper::getLocale(['global']);

        if (is_null($userModel)) {
            return $LOCALE['deleted_user'];
        }

        $kindsWithFullData = [
            'transaction',
            'application',
            'org',
            'character',
            'roles_gamemaster',
        ];

        $result = '';

        if ($link) {
            if (CURRENT_USER->isLogged()) {
                $result .= '<a href="' . ABSOLUTE_PATH . '/people/' .
                    (!is_null($userModel->sid->get()) ? $userModel->sid->get() . '/' : '') . '"' .
                    ($class !== '' ? ' class="' . $class . '"' : '') . '>';
            }
        }

        if (!is_null($userModel->id->getAsInt())) {
            $gotSomeName = false;

            if (!CURRENT_USER->blockedProfileEdit()) {
                $hidesome = $userModel->hidesome->get();

                if (
                    (!in_array(10, $hidesome) || in_array(KIND, $kindsWithFullData)) && $userModel->full_name->get() !== null
                    && !($shortName && $addNickname && (!in_array(0, $hidesome)
                        || in_array(KIND, $kindsWithFullData)) && $userModel->nick->get() !== null)
                ) {
                    $fullName = trim($userModel->full_name->get());

                    if ($shortName) {
                        $fullName = preg_replace('#\s+#', ' ', $fullName);
                        $name = explode(' ', $fullName);

                        if (!empty($name[1])) {
                            if (!preg_match('#вич|вна#', $name[1]) && ($name[2] ?? false)) {
                                $result .= $name[1];
                            } else {
                                $result .= $name[0];
                            }
                        } else {
                            $result .= $fullName;
                        }
                    } else {
                        $result .= $fullName;
                    }
                    $gotSomeName = true;
                }

                if (
                    $addNickname && (!in_array(0, $userModel->hidesome->get()) || in_array(KIND, $kindsWithFullData))
                    && $userModel->nick->get() !== null
                ) {
                    if ($gotSomeName) {
                        $result .= ' (';
                    }
                    $result .= $userModel->nick->get();

                    if ($gotSomeName) {
                        $result .= ')';
                    }
                    $gotSomeName = true;
                }
            } else {
                $showId = true;
            }

            $LOCALE_USER = LocaleHelper::getLocale(['user', 'global']);

            if ($showId && !$gotSomeName) {
                $result .= $LOCALE['user_id'] . ' ' . $userModel->sid->get();
            } elseif ($addIdInAnyCase) {
                if (!$gotSomeName) {
                    $result .= $LOCALE_USER['name_hidden'];
                }
                $result .= ' (' . $LOCALE['user_id'] . ' ' . $userModel->sid->get() . ')';
            } elseif (!$gotSomeName) {
                $result .= $LOCALE_USER['name_hidden'];
            }
        } else {
            $result .= $LOCALE['deleted_user'];
        }

        if ($link) {
            if (CURRENT_USER->isLogged()) {
                $result .= '</a>';
            }
        }

        return $result;
    }

    /** Получение полного списка всех возможных объектов подписки через тире */
    public function getSubsObjectsList(): array
    {
        $LOCALE = LocaleHelper::getLocale(['user', 'fraym_model']);

        $subsObjectsArray = [];
        $subsObjectsList = $LOCALE['elements']['subs_objects']['values'];

        foreach ($subsObjectsList as $array) {
            $subsObjectsArray[] = $array[0];
        }

        return $subsObjectsArray;
    }

    /** Превращение имени пользователя в читаемый формат */
    public function getUserName(UserModel $userModel): string
    {
        $name = explode(' ', $userModel->full_name->get());

        if ($name[1] !== '') {
            if (!preg_match('#вич|вна#', $name[1]) && $name[2] !== '') {
                $resultName = $name[1] . ' ' . $name[0];
            } elseif ($name[2] !== '') {
                $resultName = $name[0] . ' ' . $name[2];
            } else {
                $resultName = $userModel->full_name->get();
            }
        } else {
            $resultName = $userModel->full_name->get();
        }

        return $resultName;
    }

    /** Регистрация токена для браузерных уведомлений */
    public function webpushSubscribe(
        ?string $deviceId,
        ?string $endpoint,
        ?string $p256dh,
        ?string $auth,
        ?string $contentEncoding = 'aesgcm',
    ): array {
        if ($deviceId && $endpoint && $p256dh && $auth && $contentEncoding) {
            $checkExistSubscription = DB->select(
                tableName: 'user__push_subscriptions',
                criteria: [
                    'user_id' => CURRENT_USER->id(),
                    'device_id' => $deviceId,
                ],
                oneResult: true,
            );

            if ($checkExistSubscription) {
                DB->update(
                    tableName: 'user__push_subscriptions',
                    data: [
                        'endpoint' => $endpoint,
                        'p256dh' => $p256dh,
                        'auth' => $auth,
                        'content_encoding' => $contentEncoding,
                    ],
                    criteria: [
                        'id' => $checkExistSubscription['id'],
                    ],
                );
            } else {
                DB->insert(
                    tableName: 'user__push_subscriptions',
                    data: [
                        'user_id' => CURRENT_USER->id(),
                        'device_id' => $deviceId,
                        'endpoint' => $endpoint,
                        'p256dh' => $p256dh,
                        'auth' => $auth,
                        'content_encoding' => $contentEncoding,
                    ],
                );
            }

            return [
                'response' => 'success',
            ];
        } else {
            return [
                'response' => 'error',
            ];
        }
    }

    /** Удаление токена для браузерных уведомлений */
    public function webpushUnsubscribe(
        ?string $deviceId,
    ): array {
        DB->delete(
            tableName: 'user__push_subscriptions',
            criteria: [
                'user_id' => CURRENT_USER->id(),
                'device_id' => $deviceId,
            ],
        );

        return [
            'response' => 'success',
        ];
    }

    /** Проверка возможности регистрироваться */
    public function registrationIsOpen(): bool
    {
        return true;
    }

    /** Расчет и выдача процента заполнения профиля */
    public function calculateProfileCompletion(int|string $userId): int
    {
        $profileCompletion = 0;

        if (CURRENT_USER->isLogged()) {
            $userData = $this->get($userId, null, null, true);

            if (
                (
                    ($userData->em->get() !== null && $userData->em_verified->get() === '1')
                    || $userData->facebook_visible->get() !== null
                    || $userData->vkontakte_visible->get() !== null
                    || $userData->telegram->get() !== null
                )
                && $userData->full_name->get() !== null
                && $userData->phone->get() !== null
                && (
                    $userData->avatar->get() !== null && !str_contains($userData->avatar->get(), 'identicon')
                )
            ) {
                $profileCompletion = 100;
            } elseif (
                ($userData->em->get() !== null && $userData->em_verified->get() === '1')
                || $userData->facebook_visible->get() !== null
                || $userData->vkontakte_visible->get() !== null
                || $userData->telegram->get() !== null
            ) {
                $profileCompletion = 50;
            }
        }

        return $profileCompletion;
    }

    /** Отсылка новому пользователю уведомления о необходимости подтвердить email и расчета заполненности профиля */
    public function postRegister(int $id): void
    {
        $LOCALE = LocaleHelper::getLocale(['global']);
        $LOCALE_PROFILE = LocaleHelper::getLocale(['profile', 'global']);

        $userData = $this->get($id);

        if ($userData->id->getAsInt()) {
            if ($userData->em->get() !== null) {
                $idToReverify = md5($userData->id->getAsInt() . $userData->created_at->getAsTimeStamp() . $userData->em->get() . $_ENV['PROJECT_HASH_WORD']);
                $text = sprintf(
                    $LOCALE_PROFILE['verify_em']['base_text'],
                    $LOCALE['sitename'],
                    ABSOLUTE_PATH . '/profile/action=verify_em&verify_id=' . $idToReverify,
                    ABSOLUTE_PATH . '/profile/action=verify_em&verify_id=' . $idToReverify,
                );
                EmailHelper::sendMail(
                    $LOCALE['sitename'],
                    $LOCALE['admin_mail'],
                    $userData->em->get(),
                    sprintf($LOCALE_PROFILE['verify_em']['name'], $LOCALE['sitename']),
                    $text,
                    true,
                );
            }

            $topSid = DB->select(
                tableName: 'user',
                criteria: [
                    ['sid', '', [OperandEnum::NOT_NULL]],
                ],
                oneResult: true,
                order: [
                    'sid DESC',
                ],
                limit: 1,
            );

            DB->update(
                tableName: 'user',
                data: [
                    'sid' => ((int) $topSid['sid'] + 1),
                ],
                criteria: [
                    'id' => $id,
                ],
            );

            CURRENT_USER->setSid((int) $topSid['sid'] + 1);

            $this->calculateProfileCompletion($userData->id->getAsInt());
        }
    }

    /** Создание и сохранения identicon'а для пользователя */
    public function createIdenticon(UserModel $userModel): string
    {
        $uploads = $_ENV['UPLOADS'];

        $name = 'none_';

        if ($userModel->gender->get() === 2) {
            $name .= 'female';
        } else {
            $name .= 'male';
        }

        $email = $userModel->em->get();
        $login = $userModel->login->get();
        $avatar = $userModel->avatar->get();

        if ($email !== null || $login !== null) {
            $tryName = md5(md5($email !== null ? $email : $login) . $_ENV['PROJECT_HASH_WORD']);

            if (file_exists(INNER_PATH . 'public' . $_ENV['UPLOADS_PATH'] . $uploads[2]['path'] . $tryName . '.png')) {
                $name = $tryName;
            } else {
                $identicon = new Identicon();

                $identicon->identicon('', [
                    'size' => 35,
                    'backr' => [255, 255],
                    'backg' => [255, 255],
                    'backb' => [255, 255],
                    'forer' => [1, 255],
                    'foreg' => [1, 255],
                    'foreb' => [1, 255],
                    'squares' => 4,
                    'autoadd' => 1,
                    'gravatar' => 0,
                    'grey' => 0,
                ]);

                $image = $identicon->identicon_build($tryName, 300);

                ob_start();
                imagepng($image);
                $imageData = ob_get_clean();
                imagedestroy($image);

                if ($imageData && file_put_contents(INNER_PATH . 'public' . $_ENV['UPLOADS_PATH'] . $uploads[2]['path'] . $tryName . '.png', $imageData)) {
                    $name = $tryName;

                    if (!$avatar || str_contains($avatar, 'identicon')) {
                        DB->update(
                            tableName: 'user',
                            data: [
                                'avatar' => '{identicon.png:' . $tryName . '.png}',
                            ],
                            criteria: [
                                'id' => $userModel->id->getAsInt(),
                            ],
                        );
                    }
                }
            }
        }

        return ABSOLUTE_PATH . $_ENV['UPLOADS_PATH'] . $uploads[2]['path'] . $name . '.png';
    }

    /** Список пользователей в онлайне */
    public function getUsersOnlineStatuses(): array
    {
        return $this->usersOnlineStatuses;
    }

    /** Проверка наличия пользователя(-ей) на сайте */
    public function checkUserOnline(int|string|array|null $userId): int|bool
    {
        if (is_null($userId)) {
            return false;
        }

        $usersOnlineStatuses = $this->getUsersOnlineStatuses();

        if (is_array($userId)) {
            // если это массив, то нам нужно просто количество людей онлайн
            $usersOnlineCount = 0;

            if (count($userId) > 0) {
                foreach ($userId as $uId) {
                    $usersOnlineStatuses[$uId] = false;
                }
                $result = $this->getAll([
                    ['id', $userId],
                    ['updated_at', time() - 180, [OperandEnum::MORE_OR_EQUAL]],
                ]);

                foreach ($result as $userData) {
                    $usersOnlineStatuses[$userData->id->getAsInt()] = true;
                    ++$usersOnlineCount;
                }
            }
            $this->usersOnlineStatuses = $usersOnlineStatuses;

            return $usersOnlineCount;
        } elseif ((int) $userId > 0) {
            // если единичная запись, то нам нужен статус конкретного человека
            if ($userId === CURRENT_USER->id()) {
                return true;
            } elseif ($usersOnlineStatuses[$userId] ?? false) {
                $this->usersOnlineStatuses = $usersOnlineStatuses;

                return $usersOnlineStatuses[$userId];
            } else {
                $updatedData = DB->select(
                    'user',
                    ['id' => $userId],
                    true,
                    null,
                    null,
                    null,
                    false,
                    [
                        'updated_at',
                    ],
                );

                if (time() - $updatedData['updated_at'] <= 180) {
                    $usersOnlineStatuses[$userId] = true;

                    return true;
                }

                $usersOnlineStatuses[$userId] = false;
            }
        }

        $this->usersOnlineStatuses = $usersOnlineStatuses;

        return false;
    }

    /** Создание фото из профиля пользователя (если нет, то identicon) со ссылкой */
    public function photoLink(UserModel $userData, int $size = 50, bool $small = false): string
    {
        return '<img src="' . $this->photoUrl($userData) . '" width="' . $size . '" title="' .
            $this->showNameExtended($userData, true) . '"' . ($small ? ' class="small"' : '') . '>';
    }

    /** Расширенная функция создания фото из профиля пользователя (если нет, то identicon) со ссылкой + имя пользователя */
    public function photoNameLink(
        ?UserModel $userModel,
        string $size = '',
        bool $showName = true,
        string $class = '',
        string|bool $fixedtitle = '',
        bool $addIdInAnyCase = false,
        bool $noDynamicContent = false,
        bool $link = true,
    ): string {
        if (is_null($userModel)) {
            return '<div class="photoName"><a><div class="photoName_photo_wrapper"><div class="photoName_photo" style="background-image: url(\'' . ABSOLUTE_PATH . '/uploads/users/none_male.png\')"></div></div></a></div>';
        }

        $result = '<div user_id="' . $userModel->id->getAsInt() . '" ' .
            ($size !== '' ? 'style="width: ' . $size . '" ' : '') .
            ' class="photoName' .
            ($class !== '' ? ' ' . $class : '') .
            ($this->checkUserOnline($userModel->id->getAsInt()) ? ' online_marker' : '') .
            '">' .
            (
                $link ?
                '<a href="' . ABSOLUTE_PATH . '/people/' . (!is_null($userModel->sid->get()) ? $userModel->sid->get() . '/' : '') . '" ' . ($noDynamicContent ? 'class="no_dynamic_content"' : '') . '>' :
                ''
            ) . '<div class="photoName_photo_wrapper"><div class="photoName_photo' . ($class !== '' ? ' ' . $class : '') . '" style="' . DesignHelper::getCssBackgroundImage($this->photoUrl($userModel, true)) . '" ' .
            (
                $fixedtitle === false ? '' : ($fixedtitle !== '' ?
                    'title="' . $fixedtitle . '"' :
                    'title="' . $this->showNameExtended(
                        $userModel,
                        true,
                        false,
                        '',
                        false,
                        $addIdInAnyCase,
                        true,
                    )) . '"'
            ) . '></div></div>' . ($link ? '</a>' : '');

        if ($showName) {
            $result .= '<div class="photoNameNameWrapper"><div class="photoName_name">' . $this->showNameExtended(
                $userModel,
                true,
                true,
                '',
                false,
                $addIdInAnyCase,
                true,
            ) . '</div></div>';
        }

        $result .= '</div>';

        return $result;
    }

    /** Расширенная функция создания фото из профиля пользователя (если нет, то identicon) со ссылкой + имя пользователя.
     *
     * @param UserModel[] $userData
     */
    public function photoNameLinkMulti(array $userData, string|bool $fixedtitle = '', bool $link = true): string
    {
        $i = 0;
        $userCount = count($userData);
        $result = '';

        foreach ($userData as $contactData) {
            if ($i < 4) {
                $result .= $this->photoNameLink(
                    $contactData,
                    $userCount === 1 ? '' : '50%',
                    false,
                    '',
                    $fixedtitle,
                    false,
                    false,
                    $link,
                );
            }
            ++$i;
        }

        return $result;
    }

    /** Получение ссылки на фото пользователя */
    public function photoUrl(UserModel $userModel, bool $thumbnail = false): string
    {
        return FileHelper::getImagePath($userModel->avatar->get(), FileHelper::getUploadNumByType('user'), $thumbnail) ??
            $this->createIdenticon($userModel);
    }

    /** Вычистка url'ов социальных сетей */
    public function socialEncode(string $path): string
    {
        return str_replace([
            'http://',
            'https://',
            'www.',
            '/posts',
            'vkontakte.ru/',
            'vk.com/',
            '.livejournal.com',
            'twitter.com/#!/',
            'facebook.com',
            'profile.php?id=',
            'plus.google.com',
            'fotki.yandex.ru/users/',
            'linkedin.com/in/',
            't.me',
            '/',
        ], '', $path);
    }

    /** Выдача url'ов социальных сетей с иконками */
    public function socialShow(string $path, string $type = '', bool $pic = false): string
    {
        if ($type === '') {
            if (preg_match('#vkontakte.ru#', $path)) {
                $type = 'vkontakte';
            } elseif (preg_match('#vk.com#', $path)) {
                $type = 'vkontakte';
            } elseif (preg_match('#fotki.yandex.ru#', $path)) {
                $type = 'yandex';
            } elseif (preg_match('#twitter.com#', $path)) {
                $type = 'twitter';
            } elseif (preg_match('#livejournal.com#', $path)) {
                $type = 'livejournal';
            } elseif (preg_match('#facebook.com#', $path)) {
                $type = 'facebook';
            } elseif (preg_match('#plus.google.com#', $path)) {
                $type = 'googleplus';
            } elseif (preg_match('#linkedin.com#', $path)) {
                $type = 'linkedin';
            } elseif (preg_match('#t.me#', $path)) {
                $type = 'telegram';
            }
        }

        $path = $this->socialEncode($path);

        $rpath = '';

        if ($path !== '') {
            if ($type === 'vkontakte') {
                $path = preg_replace('#^m\.#', '', $path);
                $rpath .= '<a href="https://vk.com/' . $path . '" target="_blank">';

                if ($pic) {
                    $rpath .= '<img src="' . ABSOLUTE_PATH . '/files/networks/vk_icon.svg"> ' . $path;
                } else {
                    $rpath .= $path;
                }
                $rpath .= '</a>';
            } elseif ($type === 'twitter') {
                $rpath .= '<a href="http://www.twitter.com/#!/' . $path . '" target="_blank">';

                if ($pic) {
                    $rpath .= '<img src="' . ABSOLUTE_PATH . '/files/networks/twitter.svg"> ' . $path;
                } else {
                    $rpath .= $path;
                }
                $rpath .= '</a>';
            } elseif ($type === 'livejournal') {
                $rpath .= '<a href="http://' . $path . '.livejournal.com" target="_blank">';

                if ($pic) {
                    $rpath .= '<img src="' . ABSOLUTE_PATH . '/files/networks/livejournal.svg"> ' . $path;
                } else {
                    $rpath .= $path;
                }
                $rpath .= '</a>';
            } elseif ($type === 'facebook') {
                $rpath .= '<a href="http://www.facebook.com/' . $path . '" target="_blank">';

                if ($pic) {
                    $rpath .= '<img src="' . ABSOLUTE_PATH . '/files/networks/fb_icon.svg"> ' . $path;
                } else {
                    $rpath .= $path;
                }
                $rpath .= '</a>';
            } elseif ($type === 'googleplus') {
                $rpath .= '<a href="https://plus.google.com/' . $path . '/posts" target="_blank">';

                if ($pic) {
                    $rpath .= '<img src="' . ABSOLUTE_PATH . '/files/networks/google.svg"> ' . $path;
                } else {
                    $rpath .= $path;
                }
                $rpath .= '</a>';
            } elseif ($type === 'yandex') {
                $rpath .= '<a href="http://fotki.yandex.ru/users/' . $path . '/" target="_blank">';

                if ($pic) {
                    $rpath .= '<img src="' . ABSOLUTE_PATH . '/files/networks/yandex.svg"> ' . $path;
                } else {
                    $rpath .= $path;
                }
                $rpath .= '</a>';
            } elseif ($type === 'telegram') {
                $path = preg_replace('#^@#', '', $path);
                $rpath .= '<a href="http://t.me/' . $path . '" target="_blank">';

                if ($pic) {
                    $rpath .= '<img src="' . ABSOLUTE_PATH . '/files/networks/telegram.svg"> ' . $path;
                } else {
                    $rpath .= $path;
                }
                $rpath .= '</a>';
            } elseif ($type === 'linkedin') {
                $rpath .= '<a href="https://www.linkedin.com/in/' . $path . '/" target="_blank">';

                if ($pic) {
                    $rpath .= '<img src="' . ABSOLUTE_PATH . '/files/networks/linkedin.svg"> ' . $path;
                } else {
                    $rpath .= $path;
                }
                $rpath .= '</a>';
            } else {
                $rpath .= $path;
            }
        }

        return $rpath;
    }

    /** Формирование ссылки на подписку */
    public function showSubscribe(string $objTypeTo, int $objIdTo): string
    {
        $LOCALE = LocaleHelper::getLocale(['global', 'subscription']);

        if ($_ENV['SUBSCRIBE_UNSUBSCRIBE']) {
            return '<a obj_type="' . $objTypeTo . '" obj_id="' . $objIdTo . '" ' . (RightsHelper::checkRights(
                '{subscribe}',
                $objTypeTo,
                $objIdTo,
            ) ? 'class="unsubscribe">' . $LOCALE['unsubscribe'] : 'class="subscribe">' . $LOCALE['subscribe']) . '</a>';
        } else {
            return '';
        }
    }

    /** Удаление подписки */
    public function deleteSubscribe(string $objTypeTo, int $objIdTo): bool
    {
        return RightsHelper::deleteRights('{subscribe}', $objTypeTo, $objIdTo);
    }

    /** Добавление подписки */
    public function addSubscribe(string $objTypeTo, int $objIdTo): bool
    {
        return RightsHelper::addRights('{subscribe}', $objTypeTo, $objIdTo);
    }

    /** Переотсылка проверочного email'а подтверждения email'а */
    public function reverifyEm(): array
    {
        $LOCALE = LocaleHelper::getLocale(['global']);
        $LOCALE_PROFILE = LocaleHelper::getLocale(['profile', 'global']);

        $returnArr = [];

        $userData = $this->get(CURRENT_USER->id(), null, null, true);

        if ($userData->id->getAsInt()) {
            $idToReverify = md5($userData->id->getAsInt() . $userData->created_at->getAsTimeStamp() . $userData->em->get() . $_ENV['PROJECT_HASH_WORD']);
            $text = sprintf(
                $LOCALE_PROFILE['verify_em']['base_text'],
                $LOCALE['sitename'],
                ABSOLUTE_PATH . '/profile/action=verify_em&verify_id=' . $idToReverify,
                ABSOLUTE_PATH . '/profile/action=verify_em&verify_id=' . $idToReverify,
            );

            if (
                EmailHelper::sendMail(
                    $LOCALE['sitename'],
                    $LOCALE['admin_mail'],
                    $userData->em->get(),
                    sprintf($LOCALE_PROFILE['verify_em']['name'], $LOCALE['sitename']),
                    $text,
                    true,
                )
            ) {
                $returnArr = [
                    'response' => 'success',
                    'response_text' => sprintf(
                        $LOCALE_PROFILE['messages']['verification_link_sent'],
                        $userData->em->get(),
                    ),
                ];
            } else {
                $returnArr = [
                    'response' => 'error',
                    'response_text' => sprintf(
                        $LOCALE_PROFILE['messages']['verification_link_not_sent'],
                        $userData->em->get(),
                    ),
                ];
            }
        }

        return $returnArr;
    }

    /** Вспомнить пароль */
    public function remindPassword(string $userEmail): array
    {
        $LOCALE = LocaleHelper::getLocale(['login', 'global', 'messages']);

        $userData = DB->select('user', ['em' => $userEmail], true, ['id']);

        if ($userEmail !== '' && $userData['id'] !== '') {
            $pass = '';
            $salt = 'abcdefghijklmnopqrstuvwxyz123456789';
            srand((int) ((float) microtime() * 1000000));
            $i = 0;

            while ($i <= 7) {
                $num = rand() % 35;
                $tmp = mb_substr($salt, $num, 1);
                $pass .= $tmp;
                ++$i;
            }

            DB->update(
                tableName: 'user',
                data: [
                    'password_hashed' => AuthHelper::hashPassword($pass),
                ],
                criteria: [
                    'id' => $userData['id'],
                ],
            );

            $myname = str_replace(['http://', '/'], '', ABSOLUTE_PATH);
            $contactemail = $userEmail;

            $message = $userData['full_name'] . sprintf(
                $LOCALE['remind_message'],
                $myname,
                $pass,
            );
            $subject = $LOCALE['remind_subject'] . ' ' . $myname;

            if (EmailHelper::sendMail($myname, '', $contactemail, $subject, $message)) {
                $returnArr = [
                    'response' => 'success',
                    'response_text' => $LOCALE['new_pass_sent'],
                ];
            } else {
                $returnArr = [
                    'response' => 'error',
                    'response_error_code' => 'error_while_sending',
                    'response_text' => $LOCALE['error_while_sending'],
                ];
            }
        } else {
            $returnArr = [
                'response' => 'error',
                'response_error_code' => 'no_email_found_in_db',
                'response_text' => $LOCALE['no_email_found_in_db'],
            ];
        }

        return $returnArr;
    }

    /** Обновить капчу */
    public function getCaptcha(): array
    {
        return UniversalHelper::getCaptcha();
    }

    public function getRightsContext(): array
    {
        return CURRENT_USER->isAdmin() ? UserModel::CONTEXT : [];
    }

    public function getSidDefault(): ?int
    {
        return CURRENT_USER->sid();
    }

    public function checkRights(): string
    {
        return 'id=' . CURRENT_USER->id();
    }
}
