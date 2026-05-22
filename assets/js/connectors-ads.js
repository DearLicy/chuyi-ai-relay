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

    function createCard(card) {
        const article = document.createElement('article');
        article.className = 'chuyi-ai-relay-connectors-ad__card';

        const icon = card.icon ? '<img class="chuyi-ai-relay-connectors-ad__icon" src="' + encodeURI(card.icon) + '" alt="" loading="lazy" />' : '<span class="chuyi-ai-relay-connectors-ad__icon" aria-hidden="true"></span>';

        article.innerHTML = icon
            + '<div>'
            + '<h3 class="chuyi-ai-relay-connectors-ad__title">' + escapeText(card.title) + '</h3>'
            + '<a class="components-button is-secondary is-compact" href="' + encodeURI(card.url) + '" target="_blank" rel="noopener noreferrer">立即查看</a>'
            + '</div>';

        return article;
    }

    function createAds() {
        const root = document.createElement('section');
        root.className = 'chuyi-ai-relay-connectors-ads';
        root.setAttribute('aria-label', '连接器推荐');

        const grid = document.createElement('div');
        grid.className = 'chuyi-ai-relay-connectors-ads__grid';

        config.cards.forEach(function (card) {
            grid.appendChild(createCard(card));
        });

        root.appendChild(grid);

        return root;
    }

    function findConnectorsList() {
        const lists = Array.from(document.querySelectorAll('[role="list"]'));
        return lists.find(function (list) {
            return list.closest('.connectors-page') && list.children.length > 0;
        }) || null;
    }

    function mount() {
        if (document.querySelector('.chuyi-ai-relay-connectors-ads')) {
            return true;
        }

        const list = findConnectorsList();
        if (!list || !list.parentElement) {
            return false;
        }

        list.parentElement.insertBefore(createAds(), list);
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
