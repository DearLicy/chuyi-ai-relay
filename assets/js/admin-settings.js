(function () {
    'use strict';

    const config = window.chuyiAiRelaySettings;
    if (!config || !config.ajaxUrl || !config.actions || !config.nonce) {
        return;
    }

    const settingsForm = document.getElementById('chuyi-ai-relay-settings-form');
    const fetchButton = document.getElementById('chuyi-ai-relay-fetch-models');
    const fetchSpinner = document.getElementById('chuyi-ai-relay-fetch-spinner');
    const fetchResult = document.getElementById('chuyi-ai-relay-fetch-result');
    const saveButton = document.getElementById('chuyi-ai-relay-save-button');
    let saving = false;
    let saveFeedbackTimer = null;
    const defaultSaveButtonText = saveButton ? (saveButton.value || saveButton.textContent || '') : '';

    function setSpinner(spinner, active) {
        if (!spinner) {
            return;
        }
        spinner.classList.toggle('is-active', active);
    }

    function setButtonBusy(button, busy) {
        if (!button) {
            return;
        }
        button.disabled = busy;
    }

    function setButtonText(button, text) {
        if (!button || !text) {
            return;
        }

        if ('value' in button) {
            button.value = text;
            return;
        }

        button.textContent = text;
    }

    function showSaveButtonFeedback(button, text) {
        if (!button || !text) {
            return;
        }

        window.clearTimeout(saveFeedbackTimer);
        setButtonText(button, text);
        saveFeedbackTimer = window.setTimeout(function () {
            setButtonText(button, defaultSaveButtonText);
            saveFeedbackTimer = null;
        }, 1800);
    }

    function clearNode(node) {
        if (!node) {
            return;
        }
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function setNotice(target, message, isError) {
        if (!target) {
            return;
        }

        clearNode(target);

        const notice = document.createElement('div');
        notice.className = `notice ${isError ? 'notice-error' : 'notice-success'} inline`;

        const paragraph = document.createElement('p');
        paragraph.textContent = message || (isError ? config.texts.requestFailed : config.texts.saved);
        notice.appendChild(paragraph);
        target.appendChild(notice);
    }

    function renderTestResult(target, payload, isError) {
        if (!target) {
            return;
        }

        clearNode(target);

        const notice = document.createElement('div');
        notice.className = `notice ${isError ? 'notice-error' : 'notice-success'} inline`;

        const message = document.createElement('p');
        message.textContent = payload.message || (isError ? config.texts.requestFailed : config.texts.testSucceeded);
        notice.appendChild(message);

        if (payload.detail) {
            const detail = document.createElement('pre');
            detail.style.whiteSpace = 'pre-wrap';
            detail.style.margin = '8px 0 0';
            detail.textContent = payload.detail;
            notice.appendChild(detail);
        }

        const previewUrl = !isError && payload.type === 'image' && payload.previewUrl ? payload.previewUrl : '';
        if (previewUrl) {
            const link = document.createElement('a');
            link.href = previewUrl;
            link.target = '_blank';
            link.rel = 'noreferrer noopener';
            link.style.display = 'block';
            link.style.marginTop = '10px';

            const image = document.createElement('img');
            image.src = previewUrl;
            image.alt = '初一中转图像测试结果';
            image.style.maxWidth = '100%';
            image.style.height = 'auto';
            image.style.border = '1px solid #dcdcde';
            image.style.borderRadius = '4px';

            link.appendChild(image);
            notice.appendChild(link);
        }

        target.appendChild(notice);
    }

    async function postAjax(formData) {
        formData.set('nonce', config.nonce);

        const response = await fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        });

        let json = null;
        try {
            json = await response.json();
        } catch (error) {
            throw new Error(config.texts.requestFailed);
        }

        if (!response.ok || !json.success) {
            const message = json && json.data && json.data.message ? json.data.message : config.texts.requestFailed;
            throw new Error(message);
        }

        return json.data || {};
    }

    function applyRenderedState(data) {
        const baseUrlInput = settingsForm ? settingsForm.querySelector('[name="base_url"]') : null;
        const modelsTextarea = settingsForm ? settingsForm.querySelector('[name="models"]') : null;
        const modelsTable = document.getElementById('chuyi-ai-relay-models-table');
        const testForms = document.getElementById('chuyi-ai-relay-test-forms');
        const overviewBaseUrl = document.getElementById('chuyi-ai-relay-overview-base-url');
        const overviewModelCount = document.getElementById('chuyi-ai-relay-overview-model-count');
        const overviewCapabilities = document.getElementById('chuyi-ai-relay-overview-capabilities');

        if (baseUrlInput && typeof data.baseUrl === 'string') {
            baseUrlInput.value = data.baseUrl;
        }
        if (modelsTextarea && typeof data.modelsText === 'string') {
            modelsTextarea.value = data.modelsText;
        }
        if (modelsTable && typeof data.modelsTableHtml === 'string') {
            modelsTable.innerHTML = data.modelsTableHtml;
        }
        if (testForms && typeof data.testFormsHtml === 'string') {
            testForms.innerHTML = data.testFormsHtml;
        }
        if (overviewBaseUrl && typeof data.baseUrlLabel === 'string') {
            overviewBaseUrl.textContent = data.baseUrlLabel;
        }
        if (overviewModelCount && typeof data.modelCount === 'string') {
            overviewModelCount.textContent = data.modelCount;
        }
        if (overviewCapabilities && typeof data.capabilitySummary === 'string') {
            overviewCapabilities.textContent = data.capabilitySummary;
        }
    }

    if (settingsForm) {
        settingsForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (saving) {
                return;
            }

            saving = true;
            const submitButton = saveButton || settingsForm.querySelector('[type="submit"]');
            const formData = new FormData(settingsForm);
            formData.set('action', config.actions.save);

            window.clearTimeout(saveFeedbackTimer);
            setButtonText(submitButton, config.texts.saving);
            setButtonBusy(submitButton, true);

            try {
                const data = await postAjax(formData);
                applyRenderedState(data);
                showSaveButtonFeedback(submitButton, data.message || config.texts.saved);
            } catch (error) {
                showSaveButtonFeedback(submitButton, config.texts.saveFailed || config.texts.requestFailed);
            } finally {
                saving = false;
                setButtonBusy(submitButton, false);
            }
        });

        document.addEventListener('keydown', function (event) {
            const key = typeof event.key === 'string' ? event.key.toLowerCase() : '';
            if (key !== 's' || (!event.ctrlKey && !event.metaKey)) {
                return;
            }

            event.preventDefault();
            if (saving) {
                return;
            }

            if (typeof settingsForm.requestSubmit === 'function') {
                settingsForm.requestSubmit();
                return;
            }

            settingsForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        });
    }

    if (fetchButton && settingsForm) {
        fetchButton.addEventListener('click', async function () {
            const formData = new FormData();
            const baseUrlInput = settingsForm.querySelector('[name="base_url"]');
            formData.set('action', config.actions.fetch);
            formData.set('base_url', baseUrlInput ? baseUrlInput.value : '');

            setSpinner(fetchSpinner, true);
            setButtonBusy(fetchButton, true);
            clearNode(fetchResult);

            try {
                const data = await postAjax(formData);
                applyRenderedState(data);
                setNotice(fetchResult, data.message || config.texts.modelsFetched, false);
            } catch (error) {
                setNotice(fetchResult, error.message, true);
            } finally {
                setSpinner(fetchSpinner, false);
                setButtonBusy(fetchButton, false);
            }
        });
    }

    document.addEventListener('submit', async function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.classList.contains('chuyi-ai-relay-test-form')) {
            return;
        }

        event.preventDefault();

        const actionInput = form.querySelector('[name="action"]');
        const action = form.getAttribute('data-test-action') || (actionInput ? actionInput.value : '');
        const submitButton = form.querySelector('[type="submit"]');
        const spinner = form.querySelector('.spinner');
        const result = form.querySelector('.chuyi-ai-relay-test-result');
        const formData = new FormData(form);
        formData.set('action', action || config.actions.testText);

        setSpinner(spinner, true);
        setButtonBusy(submitButton, true);
        clearNode(result);

        try {
            const data = await postAjax(formData);
            renderTestResult(result, data, false);
        } catch (error) {
            renderTestResult(result, { message: error.message }, true);
        } finally {
            setSpinner(spinner, false);
            setButtonBusy(submitButton, false);
        }
    });
}());