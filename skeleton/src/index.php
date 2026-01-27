<?php

declare(strict_types=1);

namespace App;

use App\CMSVC\Error404\Error404Controller;
use App\Template\MainTemplate;
use Fraym\BaseObject\{BaseController, BaseHelper};
use Fraym\Enum\ActionEnum;
use Fraym\Helper\{CookieHelper, DataHelper, LocaleHelper, ResponseHelper, TextHelper};
use Fraym\Interface\Response;
use Fraym\Response\{ArrayResponse, HtmlResponse};

/** Логинимся / выходим */
if ('logout' === ACTION) {
    CURRENT_USER->authLogout();
} elseif (CURRENT_USER->isLogged() && CURRENT_USER->isBanned()) {
    CURRENT_USER->authLogout(LocaleHelper::getLocale(['user'])['you_re_banned']);
}

/** Если это первая открытая страница сайта и пользователь залогинен, проверяем, нет ли cookie последней успешно сгенеренной страницы */
if (
    CURRENT_USER->isLogged() && CookieHelper::getCookie('last_page_visited')
    && !preg_match('#' . ABSOLUTE_PATH . '#', $_SERVER['HTTP_REFERER'] ?? '')
    && in_array(KIND, [$_ENV['STARTING_KIND'], ''])
) {
    if (!CURRENT_USER->getBlockAutoRedirect()) {
        $lastPageVisited = CookieHelper::getCookie('last_page_visited');
        CookieHelper::batchDeleteCookie(['last_page_visited']);

        if (!in_array($lastPageVisited, [ABSOLUTE_PATH, ABSOLUTE_PATH . '/' . $_ENV['STARTING_KIND'] . '/'])) {
            ResponseHelper::redirect($lastPageVisited);
        }
    }
}

/** Записываем данные в лог */
DataHelper::activityLog();

/** Подгружаем соответствующий запросу контроллер раздела: он в свою очередь подключает необходимые модели и вьюшку */
$RESPONSE_DATA = null;
$CMSCVName = TextHelper::snakeCaseToCamelCase(KIND);
$controllerName = 'App\\CMSVC\\' . $CMSCVName . '\\' . $CMSCVName . 'Controller';
$controller = null;

if (class_exists($controllerName)) {
    /** @var BaseHelper|BaseController $controller */
    $controller = new $controllerName();

    if ($controller instanceof BaseController) {
        $controller->construct(CMSVCinit: false);
    }

    if ($controller instanceof BaseHelper || ($controller->checkIfIsAccessible() && $controller->checkIfHasToBeAndIsAdmin())) {
        if ($controller instanceof BaseController) {
            $controller->CMSVC->init();
        }

        if (is_null(ACTION) || in_array(ACTION, ActionEnum::cases())) {
            $RESPONSE_DATA = $controller->Response();
        } elseif (method_exists($controller, ACTION)) {
            if ($controller instanceof BaseHelper || $controller->checkIfIsAccessible(ACTION)) {
                $RESPONSE_DATA = $controller->{ACTION}();
            }
        } else {
            $LOCALE_CONVERSATION = LocaleHelper::getLocale(['conversation', 'global']);
            $RESPONSE_DATA = new ArrayResponse([
                'response' => 'error',
                'response_error_code' => 'wrong_action',
                'response_text' => $LOCALE_CONVERSATION['messages']['wrong_action'],
            ]);
        }
    }
}

/** Если в результате обработки контента нет, ошибка 404 */
if (!($RESPONSE_DATA instanceof Response)) {
    (new Error404Controller())->construct(CMSVCinit: false)->init()->Default();
}

/** Подгружаем базовую локаль проекта */
$LOCALE = LocaleHelper::getLocale(['global']);

$cookieMessages = CookieHelper::getCookie('messages', true);

if ($cookieMessages && !$controller instanceof BaseHelper) {
    CookieHelper::batchDeleteCookie(['messages']);
}

if ($RESPONSE_DATA instanceof ArrayResponse) {
    $RESPONSE_RESULT = $RESPONSE_DATA->getData();

    if (!$controller instanceof BaseHelper) {
        if ($RESPONSE_RESULT['messages'] ?? false) {
            $RESPONSE_RESULT['messages'] = array_merge($RESPONSE_RESULT['messages'], $cookieMessages ?? []);
        } else {
            $RESPONSE_RESULT['messages'] = $cookieMessages ?? [];
        }
        $RESPONSE_RESULT['executionTime'] = GLOBALTIMER->getTimerDiff();
    }
    header('Access-Control-Allow-Origin: *');
    echo DataHelper::jsonFixedEncode($RESPONSE_RESULT);
} elseif ($RESPONSE_DATA instanceof HtmlResponse) {
    /** Если предоставлено альтернативное название страницы, убеждаемся, что оно идет с большой буквы */
    $PAGETITLE = $RESPONSE_DATA->getPagetitle();

    if (!is_null($PAGETITLE) && !in_array($PAGETITLE, ['', $LOCALE['sitename']])) {
        $PAGETITLE = TextHelper::mb_ucfirst($PAGETITLE);
    }

    if ($PAGETITLE === '' || is_null($PAGETITLE)) {
        $PAGETITLE = $LOCALE['sitename'];
    }

    /** Сохраняем информацию об адресе текущей страницы */
    CookieHelper::batchSetCookie(
        [
            'last_page_visited' => ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']),
        ],
    );

    if (REQUEST_TYPE->isDynamicRequest()) {
        $RESPONSE_RESULT = DataHelper::jsonFixedEncode(
            [
                'html' => $RESPONSE_DATA->getHtml(),
                'pageTitle' => $PAGETITLE,
                'messages' => $cookieMessages ?? [],
                'executionTime' => GLOBALTIMER->getTimerDiff(),
            ],
        );
        header('Access-Control-Allow-Origin: *');
        echo $RESPONSE_RESULT;
    } else {
        /** Вносим блоки информации в заданный шаблон визуализации */
        $RESPONSE_TEMPLATE = MainTemplate::asHTML();
        $RESPONSE_TEMPLATE = preg_replace('#<!--pagetitle-->#', $PAGETITLE, $RESPONSE_TEMPLATE);
        $RESPONSE_RESULT = preg_replace('#<!--maincontent-->#', DataHelper::pregQuoteReplaced($RESPONSE_DATA->getHtml()), $RESPONSE_TEMPLATE);

        /** Добавляем сообщения-нотификации */
        $messageArray = '<script>
    window["messages"] = defaultFor(window["messages"], []);';

        if ($cookieMessages) {
            foreach ($cookieMessages as $message) {
                $messageArray .= 'messages.push(Array("' . $message[0] . '","' . str_replace('"', '\"', $message[1]) . '"));';
            }
        }
        $messageArray .= '</script>';
        $RESPONSE_RESULT = preg_replace('#<!--messages-->#', $messageArray, $RESPONSE_RESULT);

        /** Выводим html */
        echo $RESPONSE_RESULT;
        echo GLOBALTIMER->getTimerDiffStr();
    }
}
