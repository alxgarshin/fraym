/** Initialization of various custom elements that may or may not be present on a given page */
async function projectInit(withDocumentEvents, updateHash) {
    /** Record the start time */
    startTime = new Date().getTime();

    updateHash = defaultFor(updateHash, false);

    blockDefaultSubmit = false;

    await loadJsCssForCMSVC();

    /** Dynamic fields */
    initDynamicFields();

    if (withDocumentEvents) {
        /** MISCELLANEOUS STANDALONE FUNCTIONS */

        /** Anti-bot */
        _(document).on('click', 'a#approvement_link', function () {
            _('input[name="approvement[0]"]').val(typeof justAnotherVar !== 'undefined' ? justAnotherVar : '');
            _('form[id^="form_"]').find('button.main').click();
        });

        /** DYNAMIC ACTIONS */

        _arSuccess('get_captcha', function (jsonData, params, target) {
            const hash = responseData(jsonData)['hash'];

            _('input[name="hash[0]"]').val(hash);
            _('div[id="field_regstamp[0]"]').find('img')?.attr('src', `/scripts/captcha/hash=${hash}`);
        })
    }

    /** Check for a hash and open the corresponding element if there is one */
    if (window.location.hash && (withDocumentEvents || popStateChanging || updateHash)) {
        customHashHandler(parseUri(currentHref));
    }

    showExecutionTime('projectInit end');
}

/** Custom hash handler */
function customHashHandler(newHrefParsed) {
    newHrefParsed = defaultFor(newHrefParsed, parseUri(newHrefParsed));

    const hash = newHrefParsed.anchor + '';

    if (/customHash/.test(hash)) {
        if (el(`a[id="${hash}"]`)) {
            scrollWindow(_(`a[id="${hash}"]`).offset().top);
        }
    }
}