(function (window, document) {
    'use strict';

    const config = window.chuyiAiRelayConnectorAds;
    if (!config || !Array.isArray(config.cards) || config.cards.length === 0) {
        return;
    }

    function escapeText(value) {
        const node = document.createElement('div');
        node.textContent = value || '';
        return node.innerHTML;
    }

    function escapeAttr(value) {
        return escapeText(value).replace(/"/g, '&quot;');
    }

    function dreamaxIcon() {
        return '<svg width="40" height="40" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="flex: 0 0 auto; line-height: 1;">'
            + '<path d="M20.616 10.835a14.147 14.147 0 01-4.45-3.001 14.111 14.111 0 01-3.678-6.452.503.503 0 00-.975 0 14.134 14.134 0 01-3.679 6.452 14.155 14.155 0 01-4.45 3.001c-.65.28-1.318.505-2.002.678a.502.502 0 000 .975c.684.172 1.35.397 2.002.677a14.147 14.147 0 014.45 3.001 14.112 14.112 0 013.679 6.453.502.502 0 00.975 0c.172-.685.397-1.351.677-2.003a14.145 14.145 0 013.001-4.45 14.113 14.113 0 016.453-3.678.503.503 0 000-.975 13.245 13.245 0 01-2.003-.678z" fill="#3186FF"></path>'
            + '<path d="M20.616 10.835a14.147 14.147 0 01-4.45-3.001 14.111 14.111 0 01-3.678-6.452.503.503 0 00-.975 0 14.134 14.134 0 01-3.679 6.452 14.155 14.155 0 01-4.45 3.001c-.65.28-1.318.505-2.002.678a.502.502 0 000 .975c.684.172 1.35.397 2.002.677a14.147 14.147 0 014.45 3.001 14.112 14.112 0 013.679 6.453.502.502 0 00.975 0c.172-.685.397-1.351.677-2.003a14.145 14.145 0 013.001-4.45 14.113 14.113 0 016.453-3.678.503.503 0 000-.975 13.245 13.245 0 01-2.003-.678z" fill="url(#chuyi-dreamax-fill-0)"></path>'
            + '<path d="M20.616 10.835a14.147 14.147 0 01-4.45-3.001 14.111 14.111 0 01-3.678-6.452.503.503 0 00-.975 0 14.134 14.134 0 01-3.679 6.452 14.155 14.155 0 01-4.45 3.001c-.65.28-1.318.505-2.002.678a.502.502 0 000 .975c.684.172 1.35.397 2.002.677a14.147 14.147 0 014.45 3.001 14.112 14.112 0 013.679 6.453.502.502 0 00.975 0c.172-.685.397-1.351.677-2.003a14.145 14.145 0 013.001-4.45 14.113 14.113 0 016.453-3.678.503.503 0 000-.975 13.245 13.245 0 01-2.003-.678z" fill="url(#chuyi-dreamax-fill-1)"></path>'
            + '<path d="M20.616 10.835a14.147 14.147 0 01-4.45-3.001 14.111 14.111 0 01-3.678-6.452.503.503 0 00-.975 0 14.134 14.134 0 01-3.679 6.452 14.155 14.155 0 01-4.45 3.001c-.65.28-1.318.505-2.002.678a.502.502 0 000 .975c.684.172 1.35.397 2.002.677a14.147 14.147 0 014.45 3.001 14.112 14.112 0 013.679 6.453.502.502 0 00.975 0c.172-.685.397-1.351.677-2.003a14.145 14.145 0 013.001-4.45 14.113 14.113 0 016.453-3.678.503.503 0 000-.975 13.245 13.245 0 01-2.003-.678z" fill="url(#chuyi-dreamax-fill-2)"></path>'
            + '<defs><linearGradient gradientUnits="userSpaceOnUse" id="chuyi-dreamax-fill-0" x1="7" x2="11" y1="15.5" y2="12"><stop stop-color="#08B962"></stop><stop offset="1" stop-color="#08B962" stop-opacity="0"></stop></linearGradient><linearGradient gradientUnits="userSpaceOnUse" id="chuyi-dreamax-fill-1" x1="8" x2="11.5" y1="5.5" y2="11"><stop stop-color="#F94543"></stop><stop offset="1" stop-color="#F94543" stop-opacity="0"></stop></linearGradient><linearGradient gradientUnits="userSpaceOnUse" id="chuyi-dreamax-fill-2" x1="3.5" x2="17.5" y1="13.5" y2="12"><stop stop-color="#FABC12"></stop><stop offset=".46" stop-color="#FABC12" stop-opacity="0"></stop></linearGradient></defs>'
            + '</svg>';
    }

    function renderIcon(card) {
        if (card.iconUrl) {
            return '<img width="40" height="40" src="' + encodeURI(card.iconUrl) + '" alt="" loading="lazy" style="flex: 0 0 auto; line-height: 1; object-fit: contain;" />';
        }

        if (!card.icon || card.icon === 'dreamax') {
            return dreamaxIcon();
        }

        if (/^https?:\/\//i.test(card.icon)) {
            return '<img width="40" height="40" src="' + encodeURI(card.icon) + '" alt="" loading="lazy" style="flex: 0 0 auto; line-height: 1; object-fit: contain;" />';
        }

        return dreamaxIcon();
    }

    function createCard(card) {
        const item = document.createElement('div');
        const id = card.id || 'dreamax';
        const labelId = 'chuyi-ai-relay-ad-' + id;
        const itemClass = card.itemClass || 'css-1bcj5ek';
        const componentClass = card.componentClass || 'css-1v73mal e19lxcc00';
        const connectorClass = card.connectorClass || 'connector-item--ai-provider-for-' + id;
        const groupClass = card.groupClass || 'components-flex components-h-stack components-v-stack css-8mn8b1 e19lxcc00';
        const rowClass = card.rowClass || 'components-flex components-h-stack css-1mfjabq e19lxcc00';
        const contentClass = card.contentClass || 'components-flex-item components-flex-block css-13y8vek e19lxcc00';
        const textStackClass = card.textStackClass || 'components-flex components-h-stack components-v-stack css-7a7sy7 e19lxcc00';
        const titleClass = card.titleClass || 'components-truncate components-text css-6jpe9g e19lxcc00';
        const descriptionClass = card.descriptionClass || 'components-truncate components-text css-8t07xj e19lxcc00';
        const actionClass = card.actionClass || 'components-flex components-h-stack css-ubkw7t e19lxcc00';
        const buttonClass = card.buttonClass || 'components-button is-secondary is-compact';

        item.setAttribute('role', 'listitem');
        item.className = itemClass + ' chuyi-ai-relay-connector-ad';
        item.dataset.chuyiAiRelayAd = id;

        item.innerHTML = '<div class="components-item ' + escapeAttr(connectorClass) + ' ' + escapeAttr(componentClass) + '" data-wp-c16t="true" data-wp-component="Item">'
            + '<div data-wp-c16t="true" data-wp-component="VStack" role="group" aria-labelledby="' + escapeAttr(labelId) + '" class="' + escapeAttr(groupClass) + '">'
            + '<div data-wp-c16t="true" data-wp-component="HStack" class="' + escapeAttr(rowClass) + '">'
            + renderIcon(card)
            + '<div data-wp-c16t="true" data-wp-component="FlexBlock" class="' + escapeAttr(contentClass) + '">'
            + '<div data-wp-c16t="true" data-wp-component="VStack" class="' + escapeAttr(textStackClass) + '">'
            + '<h2 data-wp-c16t="true" data-wp-component="Text" id="' + escapeAttr(labelId) + '" class="' + escapeAttr(titleClass) + '">' + escapeText(card.title) + '</h2>'
            + '<span data-wp-c16t="true" data-wp-component="Text" class="' + escapeAttr(descriptionClass) + '">' + escapeText(card.description || '') + '</span>'
            + '</div></div>'
            + '<div data-wp-c16t="true" data-wp-component="HStack" class="' + escapeAttr(actionClass) + '">'
            + '<a class="' + escapeAttr(buttonClass) + '" href="' + encodeURI(card.url) + '" target="_blank" rel="noopener noreferrer">' + escapeText(card.buttonText || '前往') + '</a>'
            + '</div></div></div></div>';

        return item;
    }

    function findConnectorsList() {
        const lists = Array.from(document.querySelectorAll('[role="list"]'));
        return lists.find(function (list) {
            return list.closest('.connectors-page') && list.children.length > 0;
        }) || null;
    }

    function mount() {
        const list = findConnectorsList();
        if (!list) {
            return false;
        }

        config.cards.forEach(function (card) {
            const id = card.id || 'dreamax';
            if (list.querySelector('[data-chuyi-ai-relay-ad="' + window.CSS.escape(id) + '"]')) {
                return;
            }
            list.insertBefore(createCard(card), list.firstChild);
        });

        return true;
    }

    function observeUntilMounted() {
        if (mount()) {
            return;
        }

        const app = document.getElementById('options-connectors-wp-admin-app') || document.getElementById('options-connectors-app') || document.body;
        const observer = new MutationObserver(function () {
            if (mount()) {
                observer.disconnect();
            }
        });

        observer.observe(app, {
            childList: true,
            subtree: true,
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', observeUntilMounted);
    } else {
        observeUntilMounted();
    }
}(window, document));