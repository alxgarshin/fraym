/** Инициализация различных кастомных элементов, которые могут быть, а могут и отсутствовать на конкретной странице */
async function projectInit(withDocumentEvents, updateHash) {
    /** Фиксируем время начала отработки */
    startTime = new Date().getTime();

    updateHash = defaultFor(updateHash, false);

    blockDefaultSubmit = false;

    await loadJsCssForCMSVC();

    /** Динамические поля */
    initDynamicFields();

    if (withDocumentEvents) {
        /** РАЗЛИЧНЫЕ ОТДЕЛЬНЫЕ ФУНКЦИИ */

        /** Антибот */
        _(document).on('click', 'a#approvement_link', function () {
            _('input[name="approvement[0]"]').val(justAnotherVar);
            _('form[id^="form_"]').find('button.main').click();
        });

        /** ДИНАМИЧЕСКИЕ ДЕЙСТВИЯ */

        _arSuccess('get_captcha', function (jsonData, params, target) {
            _('input[name="hash[0]"]').val(jsonData['hash']);
            _('div[id="field_regstamp[0]"]').find('img')?.attr('src', `/scripts/captcha/hash=${jsonData['hash']}`);
        })
    }

    /** Проверка наличия hash'а и открытие соответствующего элемента, если он есть */
    if (window.location.hash && (withDocumentEvents || popStateChanging || updateHash)) {
        customHashHandler(parseUri(currentHref));
    }

    showExecutionTime('projectInit end');
}

/** Кастомная функция обработки хэша */
function customHashHandler(newHrefParsed) {
    newHrefParsed = defaultFor(newHrefParsed, parseUri(newHrefParsed));

    const hash = newHrefParsed.anchor + '';

    if (/customHash/.test(hash)) {
        if (el(`a[id="${hash}"]`)) {
            scrollPageTop = false;

            scrollWindow(_(`a[id="${hash}"]`).offset().top);
        }
    }
}