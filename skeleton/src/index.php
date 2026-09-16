<?php

declare(strict_types=1);

namespace App;

use App\CMSVC\Error404\Error404Controller;
use App\Template\MainTemplate;
use Fraym\BaseObject\{BaseController, BaseHelper};
use Fraym\Enum\{ActionEnum, ResponseErrorCodeEnum};
use Fraym\Helper\{AuthHelper, CookieHelper, DataHelper, LocaleHelper, ResponseHelper, TextHelper};
use Fraym\Interface\Response;
use Fraym\Response\{ArrayResponse, HtmlResponse};

/** Log in / log out */
if ('logout' === ACTION) {
    CURRENT_USER->authLogout();
} elseif (CURRENT_USER->isLogged() && CURRENT_USER->isBanned()) {
    CURRENT_USER->authLogout(LocaleHelper::getLocale(['user'])['you_re_banned']);
}

/** If this is the first opened page of the site and the user is logged in, check for a cookie with the last successfully generated page */
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

/** Write data to the log */
DataHelper::activityLog();

/** Load the section controller for the request: it in turn loads the required models and view */
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
            $BASEFUNC_LOCALE = LocaleHelper::getLocale(['fraym', 'basefunc']);
            $RESPONSE_DATA = new ArrayResponse([
                'response' => 'error',
                'response_error_code' => ResponseErrorCodeEnum::wrongAction->value,
                'response_text' => $BASEFUNC_LOCALE['wrong_action'] ?? null,
            ]);
        }
    }
}

/** If processing produced no content, return 404 */
if (!($RESPONSE_DATA instanceof Response)) {
    if (REQUEST_TYPE->isApiRequest()) {
        ResponseHelper::response404();
    }

    $RESPONSE_DATA = (new Error404Controller())->construct(CMSVCinit: false)->init()->Default();
}

/** Load the base project locale */
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
    ResponseHelper::setCorsHeaders();
    echo DataHelper::jsonFixedEncode(ResponseHelper::buildEnvelope($RESPONSE_RESULT));
} elseif ($RESPONSE_DATA instanceof HtmlResponse) {
    /** If an alternative page title is provided, make sure it starts with a capital letter */
    $PAGETITLE = $RESPONSE_DATA->getPagetitle();

    if (!is_null($PAGETITLE) && !in_array($PAGETITLE, ['', $LOCALE['sitename']])) {
        $PAGETITLE = TextHelper::mb_ucfirst($PAGETITLE);
    }

    if ($PAGETITLE === '' || is_null($PAGETITLE)) {
        $PAGETITLE = $LOCALE['sitename'];
    }

    /** Save the address of the current page */
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
                'fraymVersion' => \Fraym\Kernel::FRAYM_VERSION,
            ],
        );
        ResponseHelper::setCorsHeaders();
        echo $RESPONSE_RESULT;
    } else {
        /** Put the information blocks into the given rendering template */
        $RESPONSE_TEMPLATE = MainTemplate::asHTML();
        $RESPONSE_TEMPLATE = preg_replace('#<!--pagetitle-->#', $PAGETITLE, $RESPONSE_TEMPLATE);
        $RESPONSE_RESULT = preg_replace('#<!--maincontent-->#', DataHelper::pregQuoteReplaced($RESPONSE_DATA->getHtml()), $RESPONSE_TEMPLATE);

        /** Add notification messages and the CSRF token */
        $messagesPairs = [];

        if ($cookieMessages) {
            foreach ($cookieMessages as $message) {
                $messagesPairs[] = [$message[0], $message[1]];
            }
        }

        /** JSON_HEX_* escape < > & ' " → safe inside <script> (cannot break out of the tag/string) */
        $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

        $messageArray = '<script>
    window["messages"] = defaultFor(window["messages"], []).concat(' . (json_encode($messagesPairs, $jsonFlags) ?: '[]') . ');';

        if (CURRENT_USER->isLogged()) {
            $messageArray .= 'window["csrfToken"] = ' . (json_encode(AuthHelper::generateCsrfToken(), $jsonFlags) ?: '""') . ';';
        }

        $messageArray .= '</script>';
        $RESPONSE_RESULT = preg_replace('#<!--messages-->#', $messageArray, $RESPONSE_RESULT);

        /** Output html */
        echo $RESPONSE_RESULT;
        echo GLOBALTIMER->getTimerDiffStr();
    }
}
