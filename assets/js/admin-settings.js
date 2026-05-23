(function (window, document, wp) {
    'use strict';

    const config = window.chuyiAiRelayAdmin || {};
    const rootNode = document.getElementById('chuyi-ai-relay-admin-root');
    if (!rootNode || !wp || !wp.element || !wp.components || !wp.apiFetch) {
        return;
    }

    const { createElement: h, useEffect, useMemo, useState } = wp.element;
    const __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function (text) { return text; };
    const {
        Button,
        Card,
        CardBody,
        CheckboxControl,
        Flex,
        FlexBlock,
        FlexItem,
        Notice,
        Panel,
        PanelBody,
        SelectControl,
        Spinner,
        TextControl,
        TextareaControl,
        ToggleControl,
    } = wp.components;

    wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(config.nonce));

    const initialPage = rootNode.dataset.page || 'help';
    const restBase = (config.restUrl || '').replace(/\/$/, '');
    const pages = config.pages || {};
    const assets = config.assets || {};
    const pageTitles = {
        settings: '接入设置',
        relays: '中转管理',
        test: '模型测试',
        prompts: '提示词管理',
        help: '使用说明',
    };

    function clonePrompts(prompts) {
        return (Array.isArray(prompts) ? prompts : []).map(function (prompt) {
            return Object.assign({}, prompt);
        });
    }

    function promptFormsFromItems(items) {
        const forms = {};
        (Array.isArray(items) ? items : []).forEach(function (prompt) {
            forms[prompt.ability] = {
                enabled: !!prompt.enabled,
                mode: prompt.mode || 'replace',
                instruction: prompt.instruction || prompt.default_instruction || '',
            };
        });
        return forms;
    }

    function route(path, options) {
        return wp.apiFetch(Object.assign({ url: restBase + path }, options || {}));
    }

    function cloneRelays(relays) {
        return (Array.isArray(relays) ? relays : []).map(function (relay) {
            return Object.assign({}, relay, {
                models: (Array.isArray(relay.models) ? relay.models : []).map(function (model) {
                    return Object.assign({}, model, {
                        capabilities: Array.isArray(model.capabilities) ? model.capabilities.slice() : [],
                    });
                }),
            });
        });
    }

    function uniqueKey() {
        return Math.random().toString(16).slice(2, 10) + Date.now().toString(16).slice(-4);
    }

    function defaultRelay() {
        return {
            key: uniqueKey(),
            enabled: true,
            name: '初一 AI 中转',
            site_url: '',
            mode: 'openai',
            image_endpoint: 'image',
            models: [],
        };
    }

    function capabilityLabel(value) {
        const labels = {
            text_generation: '文本',
            vision: '视觉',
            image_generation: '生图',
        };
        return labels[value] || value;
    }

    function getRelayName(relay) {
        return relay && relay.name ? relay.name : '未命名中转';
    }

    function getProviderId(relay) {
        if (!relay || !relay.key || relay.key === 'default') {
            return 'chuyi-relay';
        }
        return 'chuyi-relay-' + String(relay.key).replace(/_/g, '-');
    }

    function getModelsByType(relay, type) {
        const models = Array.isArray(relay && relay.models) ? relay.models : [];
        return models.filter(function (model) {
            const caps = Array.isArray(model.capabilities) ? model.capabilities : [];
            return type === 'image' ? caps.indexOf('image_generation') !== -1 : caps.indexOf('text_generation') !== -1;
        });
    }

    function formatLatency(status) {
        const latency = status && status.latency ? parseInt(status.latency, 10) : 0;
        return latency > 0 ? latency + 'ms' : '未测速';
    }

    function latencyLevel(status) {
        if (!status || status.ok === false) {
            return 'offline';
        }
        if (status.ok !== true || !status.latency) {
            return 'unknown';
        }

        const latency = parseInt(status.latency, 10);
        if (latency < 800) {
            return 'low';
        }
        if (latency <= 2000) {
            return 'medium';
        }
        return 'high';
    }

    function parseMarkdownImages(text) {
        const source = typeof text === 'string' ? text : '';
        const images = [];
        const pattern = /!\[([^\]]*)\]\(\s*(<([^>]+)>|[^\s)]+)(?:\s+["'][^"']*["'])?\s*\)/g;
        let match;

        while ((match = pattern.exec(source)) !== null) {
            const url = match[3] || match[2] || '';
            if (/^(https?:\/\/|data:image\/)/i.test(url)) {
                images.push({ alt: match[1] || '生成图片', url: url });
            }
        }

        return images;
    }

    function TestResult(props) {
        const text = props.value || '';
        const images = parseMarkdownImages(text);

        return h('div', { className: 'chuyi-ai-relay-result' },
            images.length > 0 && h('div', { className: 'chuyi-ai-relay-result__images' }, images.map(function (image, index) {
                return h('a', { key: index, href: image.url, target: '_blank', rel: 'noreferrer' },
                    h('img', { src: image.url, alt: image.alt })
                );
            })),
            h('pre', { className: 'chuyi-ai-relay-result__text' }, text)
        );
    }

    function App() {
        const [loading, setLoading] = useState(true);
        const [currentPage, setCurrentPage] = useState(initialPage);
        const [saving, setSaving] = useState(false);
        const [busy, setBusy] = useState('');
        const [payload, setPayload] = useState({ relays: [], stats: {}, modes: [], imageEndpoints: [], capabilities: [] });
        const [relays, setRelays] = useState([]);
        const [notice, setNotice] = useState(null);
        const [testState, setTestState] = useState({ slotId: '', type: 'text', model: '', prompt: '' });
        const [testResult, setTestResult] = useState('等待测试。');
        const [prompts, setPrompts] = useState([]);
        const [promptForms, setPromptForms] = useState({});
        const [promptsLoaded, setPromptsLoaded] = useState(false);

        function hydrate(data, options) {
            const nextRelays = cloneRelays(data.relays);
            const shouldResetPageState = options && options.resetPageState;
            setPayload(data);
            setRelays(nextRelays);
            setNotice(shouldResetPageState ? null : (data.notice || null));
            setTestState(function (prev) {
                const first = nextRelays.find(function (relay) { return relay.enabled && relay.site_url; }) || nextRelays[0] || null;
                const slotId = shouldResetPageState ? (first ? first.key : '') : (prev.slotId || (first ? first.key : ''));
                return shouldResetPageState
                    ? { slotId: slotId, type: 'text', model: '', prompt: '' }
                    : Object.assign({}, prev, { slotId: slotId });
            });
            if (shouldResetPageState) {
                setTestResult('等待测试。');
                setBusy('');
                setSaving(false);
            }
        }

        function load(options) {
            setLoading(true);
            route('/settings')
                .then(function (data) { hydrate(data, options || {}); })
                .catch(function (error) {
                    setNotice({ status: 'error', message: error.message || '加载失败。' });
                })
                .finally(function () {
                    setLoading(false);
                });
        }

        function loadPrompts(options) {
            const force = options && options.force;
            if (promptsLoaded && !force) {
                return Promise.resolve(prompts);
            }
            setBusy('prompts:load');
            return route('/prompts')
                .then(function (data) {
                    const items = clonePrompts(data.prompts);
                    setPrompts(items);
                    setPromptForms(promptFormsFromItems(items));
                    setPromptsLoaded(true);
                    return items;
                })
                .catch(function (error) {
                    setNotice({ status: 'error', message: error.message || '提示词加载失败。' });
                })
                .finally(function () {
                    setBusy(function (current) { return current === 'prompts:load' ? '' : current; });
                });
        }

        function hydratePrompts(data) {
            const items = clonePrompts(data.prompts);
            setPrompts(items);
            setPromptForms(promptFormsFromItems(items));
            setPromptsLoaded(true);
            setNotice(data.notice || null);
        }

        function updatePromptForm(ability, patch) {
            setPromptForms(function (items) {
                return Object.assign({}, items, {
                    [ability]: Object.assign({}, items[ability] || {}, patch),
                });
            });
        }

        function savePrompt(ability) {
            const form = promptForms[ability] || {};
            const instruction = String(form.instruction || '').trim();
            if (!instruction) {
                setNotice({ status: 'error', message: '提示词不能为空。' });
                return Promise.resolve();
            }
            setBusy('prompt:save:' + ability);
            return route('/prompts/' + ability, {
                method: 'POST',
                data: {
                    enabled: !!form.enabled,
                    mode: form.mode || 'replace',
                    instruction: instruction,
                },
            })
                .then(hydratePrompts)
                .catch(function (error) {
                    setNotice({ status: 'error', message: error.message || '提示词保存失败。' });
                })
                .finally(function () {
                    setBusy('');
                });
        }

        function resetPrompt(ability) {
            setBusy('prompt:reset:' + ability);
            return route('/prompts/' + ability, { method: 'DELETE' })
                .then(hydratePrompts)
                .catch(function (error) {
                    setNotice({ status: 'error', message: error.message || '恢复默认失败。' });
                })
                .finally(function () {
                    setBusy('');
                });
        }

        useEffect(function () {
            load();
        }, []);

        useEffect(function () {
            if (currentPage === 'prompts') {
                loadPrompts({ force: !promptsLoaded });
            }
        }, [currentPage]);

        useEffect(function () {
            function pageFromUrl(value) {
                try {
                    const url = new URL(value, window.location.href);
                    const pageParam = url.searchParams.get('page');
                    return pageParam === 'chuyi-ai-relay-settings'
                        ? 'settings'
                        : (pageParam === 'chuyi-ai-relay-relays' ? 'relays' : (pageParam === 'chuyi-ai-relay-test' ? 'test' : (pageParam === 'chuyi-ai-relay-prompts' ? 'prompts' : (pageParam === 'chuyi-ai-relay-help' ? 'help' : ''))));
                } catch (error) {
                    return '';
                }
            }

            function handlePopState() {
                const nextPage = pageFromUrl(window.location.href) || 'help';
                setCurrentPage(nextPage);
                load({ resetPageState: true });
            }

            function handleAdminMenuClick(event) {
                if (event.defaultPrevented) {
                    return;
                }
                const link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
                if (!link) {
                    return;
                }
                const nextPage = pageFromUrl(link.href);
                if (!nextPage) {
                    return;
                }
                event.preventDefault();
                switchPage(nextPage, link.href);
            }

            window.addEventListener('popstate', handlePopState);
            document.addEventListener('click', handleAdminMenuClick);
            return function () {
                window.removeEventListener('popstate', handlePopState);
                document.removeEventListener('click', handleAdminMenuClick);
            };
        }, []);

        const selectedRelay = useMemo(function () {
            return relays.find(function (relay) { return relay.key === testState.slotId; }) || relays[0] || null;
        }, [relays, testState.slotId]);

        const selectableModels = useMemo(function () {
            return getModelsByType(selectedRelay, testState.type);
        }, [selectedRelay, testState.type]);

        useEffect(function () {
            if (!selectableModels.length) {
                setTestState(function (prev) { return Object.assign({}, prev, { model: '' }); });
            return;
            }
            if (!selectableModels.some(function (model) { return model.id === testState.model; })) {
                setTestState(function (prev) { return Object.assign({}, prev, { model: selectableModels[0].id }); });
            }
        }, [testState.type, testState.slotId, selectableModels.length]);

        function updateRelay(index, patch) {
            setRelays(function (items) {
                return items.map(function (relay, itemIndex) {
                    return itemIndex === index ? Object.assign({}, relay, patch) : relay;
                });
            });
        }

        function updateModel(relayIndex, modelIndex, patch) {
            setRelays(function (items) {
                return items.map(function (relay, itemIndex) {
                    if (itemIndex !== relayIndex) {
                        return relay;
                    }
                    const models = (Array.isArray(relay.models) ? relay.models : []).map(function (model, currentModelIndex) {
                        return currentModelIndex === modelIndex ? Object.assign({}, model, patch) : model;
                    });
                    return Object.assign({}, relay, { models: models });
                });
            });
        }

        function saveRelays(nextRelays) {
            setSaving(true);
            return route('/settings', {
            method: 'POST',
                data: { relays: nextRelays || relays },
            })
                .then(function (data) {
                    hydrate(data);
                    return data;
                })
                .catch(function (error) {
                    setNotice({ status: 'error', message: error.message || '保存失败。' });
                })
                .finally(function () {
                    setSaving(false);
                });
        }

        function fetchModels(slotId) {
            setBusy('fetch:' + slotId);
            return route('/fetch-models', {
                method: 'POST',
                data: { slotId: slotId },
            })
                .then(hydrate)
                .catch(function (error) {
                    setNotice({ status: 'error', message: error.message || '获取模型失败。' });
                })
                .finally(function () {
                    setBusy('');
                });
        }

        function testConnection(slotId) {
            setBusy('conn:' + slotId);
            return route('/test-connection', {
                method: 'POST',
                data: { slotId: slotId },
            })
                .then(function (data) {
                    if (Array.isArray(data.relays)) {
                        setRelays(cloneRelays(data.relays));
                    }
                })
                .catch(function (error) {
                    if (Array.isArray(error.relays)) {
                        setRelays(cloneRelays(error.relays));
                    } else {
                        setRelays(function (items) {
                            return items.map(function (relay) {
                                return relay.key === slotId ? Object.assign({}, relay, {
                                    status: {
                                        latency: error.latency || 0,
                                        ok: false,
                                        message: error.message || '连通失败。',
                                        checked: new Date().toISOString(),
                                    },
                                }) : relay;
                            });
                        });
                    }
                })
                .finally(function () {
                    setBusy('');
                });
        }

        function runTest() {
            setBusy('test');
            setTestResult('正在测试...');
            return route('/test-generation', {
                method: 'POST',
                data: testState,
            })
                .then(function (data) {
                    setTestResult(data.message || '测试成功。');
                    setNotice({ status: 'success', message: '模型测试完成。' });
                })
                .catch(function (error) {
                    setTestResult(error.message || '测试失败。');
                    setNotice({ status: 'error', message: error.message || '测试失败。' });
                })
                .finally(function () {
                    setBusy('');
                });
        }

        function addRelay() {
            setRelays(function (items) {
                return items.concat([defaultRelay()]);
            });
        }

        function removeRelay(index) {
            setRelays(function (items) {
                return items.filter(function (_, itemIndex) { return itemIndex !== index; });
            });
        }

        function deleteRelayAndSave(index) {
            const relay = relays[index] || null;
            const nextRelays = relays.filter(function (_, itemIndex) { return itemIndex !== index; });
            if (relay && relay.key) {
                setBusy('delete:' + relay.key);
            }
            setRelays(nextRelays);
            return saveRelays(nextRelays).finally(function () {
                setBusy('');
            });
        }

        function moveRelay(index, direction) {
            setRelays(function (items) {
                const next = items.slice();
                const target = index + direction;
                if (target < 0 || target >= next.length) {
                    return items;
                }
                const current = next[index];
                next[index] = next[target];
                next[target] = current;
                return next;
            });
        }

        function addModel(relayIndex) {
            setRelays(function (items) {
                return items.map(function (relay, index) {
                    if (index !== relayIndex) {
                        return relay;
                    }
                    return Object.assign({}, relay, {
                        models: (relay.models || []).concat([{ id: '', name: '', capabilities: ['text_generation'] }]),
                    });
                });
            });
        }

        function removeModel(relayIndex, modelIndex) {
            setRelays(function (items) {
                return items.map(function (relay, index) {
                    if (index !== relayIndex) {
                        return relay;
                    }
                    return Object.assign({}, relay, {
                        models: (relay.models || []).filter(function (_, currentModelIndex) { return currentModelIndex !== modelIndex; }),
                    });
                });
            });
        }

        function switchPage(nextPage, href) {
            if (nextPage === currentPage) {
                return;
            }
            setCurrentPage(nextPage);
            window.history.pushState({ chuyiAiRelayPage: nextPage }, '', href);
            load({ resetPageState: true });
        }

        function renderNotice() {
            if (!notice || !notice.message) {
                return null;
            }
            return h(Notice, {
                className: 'chuyi-ai-relay-notice',
                status: notice.status || 'info',
                isDismissible: true,
                onRemove: function () { setNotice(null); },
            }, notice.message);
        }

        function renderHeader() {
            const description = currentPage === 'settings'
                ? '添加和编辑中转站，保存后会同步为 Connector provider。'
                : (currentPage === 'relays' ? '查看运行中的中转接入，快速测速、拉取模型或清理配置。' : (currentPage === 'test' ? '选择已启用的中转站和模型，直接验证文本或生图链路。' : (currentPage === 'prompts' ? '查看和覆盖官方 AI 能力的默认系统提示词。' : '了解插件能力、配置流程、审批放行和打赏支持。')));
            const navItems = [
                { key: 'help', label: '使用说明', href: pages.help },
                { key: 'settings', label: '接入设置', href: pages.settings },
                { key: 'relays', label: '中转管理', href: pages.relays },
                { key: 'test', label: '模型测试', href: pages.test },
                { key: 'prompts', label: '提示词管理', href: pages.prompts },
            ];

            return h('div', { className: 'chuyi-ai-relay-page-head' },
                h('div', { className: 'chuyi-ai-relay-page-head__inner' },
                    h('div', { className: 'chuyi-ai-relay-page-head__copy' },
                        h('span', { className: 'chuyi-ai-relay-page-head__eyebrow' }, 'WordPress AI Relay'),
                        h('h1', null, pageTitles[currentPage] || '初一 AI 中转'),
                        h('p', null, description)
                    ),
                    h('div', { className: 'chuyi-ai-relay-page-head__actions' },
                        h(Button, { variant: 'secondary', href: pages.connectors || 'options-connectors.php' }, '打开 Connectors')
                    )
                ),
                h('nav', { className: 'chuyi-ai-relay-tabs', 'aria-label': '初一 AI 中转页面导航' }, navItems.map(function (item) {
                    return h('a', {
                        key: item.key,
                        href: item.href,
                        className: currentPage === item.key ? 'is-active' : '',
                        onClick: function (event) {
                            event.preventDefault();
                            switchPage(item.key, item.href);
                        },
                    }, item.label);
                }))
            );
        }

        function renderStats() {
            const stats = payload.stats || {};
            return h('div', { className: 'chuyi-ai-relay-grid' },
                h(StatCard, { label: '中转数量', value: String(stats.totalRelays || relays.length), desc: '当前保存的中转配置' }),
                h(StatCard, { label: '已启用', value: String(stats.enabledRelays || 0), desc: '会注册为 Connector provider' }),
                h(StatCard, { label: '模型数量', value: String(stats.totalModels || 0), desc: '已保存的模型池总数' })
            );
        }

        function renderSettingsPage() {
            return h('section', { className: 'chuyi-ai-relay-section' },
                h('div', { className: 'chuyi-ai-relay-section__head' },
                    h('div', null,
                        h('h2', null, '中转接入配置'),
                        h('p', null, '每个中转站都会生成稳定 provider ID。排序只影响显示顺序，不影响已保存的 API Key。')
                    ),
                    h('div', { className: 'chuyi-ai-relay-actions' },
                        h(Button, { variant: 'secondary', onClick: addRelay }, '添加中转站'),
                        h(Button, { variant: 'primary', isBusy: saving, disabled: saving, onClick: function () { saveRelays(); } }, saving ? '保存中...' : '保存设置')
                    )
                ),
                h('div', { className: 'chuyi-ai-relay-relay-list' }, relays.length ? relays.map(function (relay, index) {
                    return h(RelayEditor, {
                        key: relay.key || index,
                        relay: relay,
                        index: index,
                        modes: payload.modes || [],
                        capabilities: payload.capabilities || [],
                        imageEndpoints: payload.imageEndpoints || [],
                        busy: busy,
                        updateRelay: updateRelay,
                        removeRelay: removeRelay,
                        moveRelay: moveRelay,
                        addModel: addModel,
                        updateModel: updateModel,
                        removeModel: removeModel,
                        relaysCount: relays.length,
                    });
                }) : h(EmptyState, {
                    title: '还没有中转站',
                    description: '添加第一个中转站后，会同步为 WordPress Connector provider。',
                    action: h(Button, { variant: 'primary', onClick: addRelay }, '添加中转站'),
                }))
            );
        }

        function renderRelaysPage() {
            return h('section', { className: 'chuyi-ai-relay-section' },
                renderStats(),
                h('div', { className: 'chuyi-ai-relay-section__head' },
                    h('div', null,
                        h('h2', null, '中转运行状态'),
                        h('p', null, '集中查看 provider ID、协议模式、模型数量和连通性。')
                    )
                ),
                h('div', { className: 'chuyi-ai-relay-relay-cards' }, relays.length ? relays.map(function (relay, index) {
                    return h(RelayCard, {
                        key: relay.key || index,
                        relay: relay,
                        index: index,
                        busy: busy,
                        fetchModels: fetchModels,
                        testConnection: testConnection,
                        deleteRelay: deleteRelayAndSave,
                    });
                }) : h(EmptyState, {
                    title: '还没有可管理的中转站',
                    description: '请先在接入设置中添加中转站。',
                    action: h(Button, { variant: 'primary', href: pages.settings }, '前往接入设置'),
                }))
            );
        }

        function renderTestPage() {
            const relayOptions = relays.filter(function (relay) { return relay.enabled && relay.site_url; }).map(function (relay) {
                return { label: getRelayName(relay) + ' / ' + getProviderId(relay), value: relay.key };
            });
            const modelOptions = selectableModels.map(function (model) { return { label: model.name || model.id, value: model.id }; });

            return h('section', { className: 'chuyi-ai-relay-test-layout' },
                h(Card, { className: 'chuyi-ai-relay-test-card' },
                    h(CardBody, null,
                        h('div', { className: 'chuyi-ai-relay-section__head chuyi-ai-relay-section__head--compact' },
                            h('div', null,
                                h('h2', null, '统一模型测试'),
                                h('p', null, '选择中转站、测试类型和模型，直接验证当前链路。')
                            )
                        ),
                        h(SelectControl, {
                            label: '中转站',
                            value: testState.slotId,
                            options: relayOptions.length ? relayOptions : [{ label: '请先启用中转站', value: '' }],
                            onChange: function (slotId) { setTestState(Object.assign({}, testState, { slotId: slotId, model: '' })); },
                        }),
                        h(SelectControl, {
                            label: '测试类型',
                            value: testState.type,
                            options: [{ label: '文本', value: 'text' }, { label: '图片', value: 'image' }],
                            onChange: function (type) { setTestState(Object.assign({}, testState, { type: type, model: '', prompt: '' })); },
                        }),
                        h(SelectControl, {
                            label: '模型',
                            value: testState.model,
                            options: modelOptions.length ? modelOptions : [{ label: '当前类型没有可用模型', value: '' }],
                            onChange: function (model) { setTestState(Object.assign({}, testState, { model: model })); },
                        }),
                        h(TextareaControl, {
                            label: testState.type === 'image' ? '图片提示词' : '文本提示词',
                            rows: 5,
                            value: testState.prompt,
                            placeholder: testState.type === 'image' ? '生成一张极简风格的蓝色圆形图标' : '请回复：初一 AI 中转文本测试成功',
                            onChange: function (prompt) { setTestState(Object.assign({}, testState, { prompt: prompt })); },
                        }),
                        h('div', { className: 'chuyi-ai-relay-actions chuyi-ai-relay-actions--end' },
                            h(Button, { variant: 'primary', isBusy: busy === 'test', disabled: busy === 'test' || !testState.slotId || !testState.model, onClick: runTest }, '开始测试')
                        )
                    )
                ),
                h(Card, { className: 'chuyi-ai-relay-test-card' },
                    h(CardBody, null,
                        h('div', { className: 'chuyi-ai-relay-section__head chuyi-ai-relay-section__head--compact' },
                            h('div', null,
                                h('h2', null, '测试结果'),
                                h('p', null, '文本会以原始输出展示；图片会自动提取并预览。')
                            )
                        ),
                        h(TestResult, { value: testResult })
                    )
                )
            );
        }

        function renderPromptsPage() {
            const isLoadingPrompts = busy === 'prompts:load' && !promptsLoaded;

            if (isLoadingPrompts) {
                return h('section', { className: 'chuyi-ai-relay-section' },
                    h('div', { className: 'chuyi-ai-relay-loading' },
                        h(Spinner, null),
                        h('span', null, __('正在加载提示词...', 'chuyi-ai-relay'))
                    )
                );
            }

            return h('section', { className: 'chuyi-ai-relay-section chuyi-ai-relay-prompts' },
                h('div', { className: 'chuyi-ai-relay-section__head' },
                    h('div', null,
                        h('h2', null, __('提示词覆盖配置', 'chuyi-ai-relay')),
                        h('p', null, __('默认显示内置提示词。未启用覆盖时保持默认；启用覆盖后使用保存内容。', 'chuyi-ai-relay'))
                    ),
                    h('div', { className: 'chuyi-ai-relay-actions' },
                        h(Button, { variant: 'secondary', isBusy: busy === 'prompts:load', disabled: busy === 'prompts:load', onClick: function () { loadPrompts({ force: true }); } }, __('刷新提示词', 'chuyi-ai-relay'))
                    )
                ),
                h('div', { className: 'chuyi-ai-relay-prompt-list' }, prompts.length ? prompts.map(function (prompt) {
                    const ability = prompt.ability || '';
                    const form = promptForms[ability] || {
                        enabled: !!prompt.enabled,
                        mode: prompt.mode || 'replace',
                        instruction: prompt.instruction || prompt.default_instruction || '',
                    };
                    const isSavingPrompt = busy === 'prompt:save:' + ability;
                    const isResettingPrompt = busy === 'prompt:reset:' + ability;
                    return h(Card, { key: ability, className: 'chuyi-ai-relay-prompt-card' },
                        h(CardBody, null,
                            h('div', { className: 'chuyi-ai-relay-prompt-card__head' },
                                h('div', null,
                                    h('h3', null, prompt.label || ability),
                                    h('code', null, ability)
                                ),
                                h('span', { className: form.enabled ? 'chuyi-ai-relay-status is-enabled' : 'chuyi-ai-relay-status' }, form.enabled ? __('已启用覆盖', 'chuyi-ai-relay') : __('使用默认提示词', 'chuyi-ai-relay'))
                            ),
                            prompt.description && h('p', { className: 'chuyi-ai-relay-card__muted' }, prompt.description),
                            h('div', { className: 'chuyi-ai-relay-form-grid chuyi-ai-relay-prompt-grid' },
                                h(ToggleControl, {
                                    label: __('启用覆盖', 'chuyi-ai-relay'),
                                    checked: !!form.enabled,
                                    onChange: function (enabled) { updatePromptForm(ability, { enabled: enabled }); },
                                }),
                                h(SelectControl, {
                                    label: __('覆盖模式', 'chuyi-ai-relay'),
                                    value: form.mode || 'replace',
                                    options: [
                                        { label: __('替换默认提示词', 'chuyi-ai-relay'), value: 'replace' },
                                        { label: __('追加到默认提示词', 'chuyi-ai-relay'), value: 'append' },
                                    ],
                                    onChange: function (mode) { updatePromptForm(ability, { mode: mode }); },
                                }),
                                h('div', { className: 'chuyi-ai-relay-field chuyi-ai-relay-field--full' },
                                    h(TextareaControl, {
                                        label: __('提示词内容', 'chuyi-ai-relay'),
                                        rows: 12,
                                        value: form.instruction || '',
                                        onChange: function (instruction) { updatePromptForm(ability, { instruction: instruction }); },
                                    }),
                                    h('p', { className: 'chuyi-ai-relay-card__muted' }, __('保存时提示词不能为空。恢复默认会删除自定义覆盖，并回到内置默认提示词。', 'chuyi-ai-relay'))
                                )
                            ),
                            h('div', { className: 'chuyi-ai-relay-actions chuyi-ai-relay-actions--end' },
                                h(Button, { variant: 'secondary', isBusy: isResettingPrompt, disabled: isSavingPrompt || isResettingPrompt || !prompt.customized, onClick: function () { resetPrompt(ability); } }, __('恢复默认', 'chuyi-ai-relay')),
                                h(Button, { variant: 'primary', isBusy: isSavingPrompt, disabled: isSavingPrompt || isResettingPrompt, onClick: function () { savePrompt(ability); } }, __('保存提示词', 'chuyi-ai-relay'))
                            )
                        )
                    );
                }) : h(EmptyState, {
                    title: __('还没有可管理的提示词', 'chuyi-ai-relay'),
                    description: __('当前没有读取到可覆盖的默认提示词。', 'chuyi-ai-relay'),
                    action: h(Button, { variant: 'primary', onClick: function () { loadPrompts({ force: true }); } }, __('重新加载', 'chuyi-ai-relay')),
                }))
            );
        }

        function renderHelpPage() {
            const approvalUrl = pages.connectors || 'options-connectors.php';
            const wallet = 'TKu7SNWrmi3n1n6e8FJDgPAwe8oGrxXHvP';

            return h('section', { className: 'chuyi-ai-relay-section chuyi-ai-relay-help' },
                h('div', { className: 'chuyi-ai-relay-help__hero' },
                    h('span', null, 'WordPress AI Relay'),
                    h('h2', null, '把多个 AI 中转站统一接入 WordPress AI 生态'),
                    h('p', null, '初一 AI 中转会把你配置的中转站转换为 WordPress Connector provider，让支持 WordPress AI Client 的插件、主题和实验功能可以统一调用文本、视觉和生图模型。')
                ),
                h('div', { className: 'chuyi-ai-relay-help__grid' },
                    h(Card, { className: 'chuyi-ai-relay-help-card' }, h(CardBody, null,
                        h('h3', null, '插件能做什么'),
                        h('ul', null,
                            h('li', null, '统一管理多个中转站，每个中转站生成稳定 provider ID。'),
                            h('li', null, '支持 OpenAI Compatible 与 Anthropic Messages 两种协议。'),
                            h('li', null, '支持手动维护模型池，也支持从中转站一键拉取模型。'),
                            h('li', null, '支持文本、视觉、生图能力标记，便于下游功能选择模型。')
                        )
                    )),
                    h(Card, { className: 'chuyi-ai-relay-help-card' }, h(CardBody, null,
                        h('h3', null, '推荐使用流程'),
                        h('ol', null,
                            h('li', null, '进入“接入设置”，添加中转站名称、站点根 URL 和协议模式。'),
                            h('li', null, '保存设置后，打开 Connectors，为对应 provider 填写 API Key。'),
                            h('li', null, '进入“中转管理”，对中转站测速，并按需拉取模型。'),
                            h('li', null, '回到“接入设置”，检查模型能力是否正确，特别是生图模型应单独标记为“生图”。'),
                            h('li', null, '进入“模型测试”，选择中转站、测试类型和模型，验证链路是否正常。')
                        )
                    )),
                    h(Card, { className: 'chuyi-ai-relay-help-card chuyi-ai-relay-help-card--wide' }, h(CardBody, null,
                        h('h3', null, '必须完成连接器审批'),
                        h('p', null, '配置好中转站和 API Key 后，还需要到连接器审批页面放行要使用 AI 的插件或主题。未审批的插件、主题会被拦截，无法正常使用 AI 功能。'),
                        h('div', { className: 'chuyi-ai-relay-help__actions' },
                            h(Button, { variant: 'primary', href: approvalUrl }, '打开连接器审批'),
                            h(Button, { variant: 'secondary', href: pages.test }, '前往模型测试')
                        )
                    ))
                ),
                h('div', { className: 'chuyi-ai-relay-donate' },
                    h('div', { className: 'chuyi-ai-relay-section__head chuyi-ai-relay-section__head--compact' },
                        h('div', null,
                            h('h2', null, '打赏插件作者'),
                            h('p', null, '如果这个插件帮你节省了时间，可以请作者喝杯咖啡。')
                        )
                    ),
                    h('div', { className: 'chuyi-ai-relay-donate__grid' },
                        h('figure', null,
                            h('img', { src: assets.rewardWechat || '', alt: '微信支付收款码' }),
                            h('figcaption', null, '微信支付')
                        ),
                        h('figure', null,
                            h('img', { src: assets.rewardAlipay || '', alt: '支付宝收款码' }),
                            h('figcaption', null, '支付宝')
                        ),
                        h('div', { className: 'chuyi-ai-relay-donate__crypto' },
                            h('span', null, '虚拟币打赏地址'),
                            h('code', null, wallet)
                        )
                    )
                )
            );
        }

        if (loading) {
            return h('div', { className: 'chuyi-ai-relay-app chuyi-ai-relay-app--loading' },
                h('div', { className: 'chuyi-ai-relay-loading' },
                    h(Spinner, null),
                    h('span', null, '正在加载中转配置...')
                )
            );
        }

        return h('div', { className: 'chuyi-ai-relay-app' },
            renderHeader(),
            renderNotice(),
            currentPage === 'settings' && renderSettingsPage(),
            currentPage === 'relays' && renderRelaysPage(),
            currentPage === 'test' && renderTestPage(),
            currentPage === 'prompts' && renderPromptsPage(),
            currentPage === 'help' && renderHelpPage()
        );
    }

    function EmptyState(props) {
        return h('div', { className: 'chuyi-ai-relay-empty' },
            h('div', { className: 'chuyi-ai-relay-empty__mark' }, 'AI'),
            h('h3', null, props.title),
            h('p', null, props.description),
            props.action && h('div', { className: 'chuyi-ai-relay-empty__action' }, props.action)
        );
    }

    function StatCard(props) {
        return h(Card, { className: 'chuyi-ai-relay-stat' },
            h(CardBody, null,
                h('span', { className: 'chuyi-ai-relay-card__muted' }, props.label),
                h('strong', null, props.value),
                h('p', { className: 'chuyi-ai-relay-card__muted' }, props.desc)
            )
        );
    }

    function RelayCard(props) {
        const relay = props.relay;
        const providerId = getProviderId(relay, props.index);
        const modelCount = Array.isArray(relay.models) ? relay.models.length : 0;
        const status = relay.status || {};
        const siteUrl = relay.site_url || '未填写';
        const modeLabel = relay.mode === 'anthropic' ? 'Anthropic Messages' : 'OpenAI Compatible';
        const isTesting = props.busy === 'conn:' + relay.key;
        const latencyClass = latencyLevel(status);
        const latencyClassName = latencyClass === 'unknown' ? '' : ' chuyi-ai-relay-latency is-' + latencyClass;

        return h(Card, { className: 'chuyi-ai-relay-relay-card' },
            h(CardBody, null,
                h('div', { className: 'chuyi-ai-relay-relay-card__head' },
                    h('div', null,
                        h('h2', { className: 'chuyi-ai-relay-card__title' }, getRelayName(relay)),
                        h('code', null, providerId)
                    ),
                    h('span', { className: relay.enabled ? 'chuyi-ai-relay-status is-enabled' : 'chuyi-ai-relay-status' }, relay.enabled ? '已启用' : '已停用')
                ),
                h('div', { className: 'chuyi-ai-relay-relay-card__meta' },
                    h('div', null, h('span', null, '站点地址'), h('strong', null, siteUrl)),
                    h('div', null, h('span', null, '协议模式'), h('strong', null, modeLabel)),
                    h('div', null, h('span', null, '模型数量'), h('strong', null, String(modelCount))),
                    h('div', null, h('span', null, '最近延迟'), h('strong', { className: latencyClassName }, isTesting ? '测速中...' : formatLatency(status)))
                ),
                h('div', { className: 'chuyi-ai-relay-actions' },
                    h(Button, { variant: 'secondary', isBusy: isTesting, disabled: isTesting || !relay.site_url, onClick: function () { props.testConnection(relay.key); } }, '测速'),
                    h(Button, { variant: 'secondary', isBusy: props.busy === 'fetch:' + relay.key, disabled: props.busy === 'fetch:' + relay.key || !relay.site_url, onClick: function () { props.fetchModels(relay.key); } }, '拉取模型'),
                    h(Button, { variant: 'tertiary', isDestructive: true, isBusy: props.busy === 'delete:' + relay.key, disabled: props.busy === 'delete:' + relay.key, onClick: function () { props.deleteRelay(props.index); } }, '删除')
                )
            )
        );
    }

    function RelayEditor(props) {
        const relay = props.relay;
        const providerId = getProviderId(relay, props.index);
        const [isOpen, setIsOpen] = useState(false);

        function toggleOpen() {
            setIsOpen(function (open) { return !open; });
        }

        function handleHeaderKeyDown(event) {
            if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
                toggleOpen();
            }
        }

        function stopHeaderToggle(event) {
            event.stopPropagation();
        }

        return h(Panel, { className: isOpen ? 'chuyi-ai-relay-relay is-open' : 'chuyi-ai-relay-relay' },
            h('div', {
                className: 'chuyi-ai-relay-relay__head',
                role: 'button',
                tabIndex: 0,
                'aria-expanded': isOpen,
                onClick: toggleOpen,
                onKeyDown: handleHeaderKeyDown,
            },
                h('div', { className: 'chuyi-ai-relay-relay__title' },
                    h('span', { className: 'chuyi-ai-relay-relay__summary' },
                        h('span', { className: 'chuyi-ai-relay-relay__chevron', 'aria-hidden': true }, '›'),
                        h('strong', null, getRelayName(relay))
                    ),
                    h('code', null, providerId)
                ),
                h('div', { className: 'chuyi-ai-relay-actions', onClick: stopHeaderToggle, onKeyDown: stopHeaderToggle },
                    h(Button, { variant: 'secondary', disabled: props.index === 0, onClick: function () { props.moveRelay(props.index, -1); } }, '上移'),
                    h(Button, { variant: 'secondary', disabled: props.index >= props.relaysCount - 1, onClick: function () { props.moveRelay(props.index, 1); } }, '下移'),
                    h(Button, { variant: 'tertiary', isDestructive: true, onClick: function () { props.removeRelay(props.index); } }, '删除')
                )
            ),
            h(PanelBody, { opened: isOpen },
                h('div', { className: 'chuyi-ai-relay-form-grid' },
                    h(ToggleControl, {
                        label: '启用此中转',
                        checked: !!relay.enabled,
                        onChange: function (enabled) { props.updateRelay(props.index, { enabled: enabled }); },
                    }),
                    h(TextControl, {
                        label: '中转标识',
                        value: relay.key || '',
                        disabled: true,
                    }),
                    h(TextControl, {
                        label: '中转名称',
                        value: relay.name || '',
                        onChange: function (name) { props.updateRelay(props.index, { name: name }); },
                    }),
                    h(TextControl, {
                        label: '站点根 URL',
                        help: '只填 https://example.com，运行时自动拼 /v1。',
                        value: relay.site_url || '',
                        onChange: function (site_url) { props.updateRelay(props.index, { site_url: site_url }); },
                    }),
                    h(SelectControl, {
                        label: '协议模式',
                        value: relay.mode || 'openai',
                        options: props.modes.length ? props.modes : [{ label: 'OpenAI Compatible', value: 'openai' }, { label: 'Anthropic Messages', value: 'anthropic' }],
                        onChange: function (mode) { props.updateRelay(props.index, { mode: mode }); },
                    }),
                    h(SelectControl, {
                        label: '生图接口',
                        help: '每个中转站返回数据不一样，请自行选择合适的生图接口。WordPress 生图需要接口返回 base64 图片才能使用。',
                        value: relay.image_endpoint || 'image',
                        options: props.imageEndpoints && props.imageEndpoints.length ? props.imageEndpoints : [
                            { label: '图片接口 /v1/images/generations', value: 'image' },
                            { label: '对话接口 /v1/chat/completions', value: 'chat' },
                            { label: '自动尝试：先图片接口，再对话接口', value: 'auto' },
                        ],
                        onChange: function (image_endpoint) { props.updateRelay(props.index, { image_endpoint: image_endpoint }); },
                    }),
                    h('div', { className: 'chuyi-ai-relay-field chuyi-ai-relay-field--full' },
                        h(Flex, { align: 'center', justify: 'space-between' },
                            h(FlexBlock, null, h('label', null, '模型池')),
                            h(FlexItem, null, h(Button, { variant: 'secondary', onClick: function () { props.addModel(props.index); } }, '添加模型'))
                        ),
                        h('div', { className: 'chuyi-ai-relay-model-list' }, (relay.models || []).map(function (model, modelIndex) {
                            return h(ModelRow, {
                                key: modelIndex,
                                model: model,
                                relayIndex: props.index,
                                modelIndex: modelIndex,
                                capabilities: props.capabilities,
                                updateModel: props.updateModel,
                                removeModel: props.removeModel,
                            });
                        }))
                    )
                )
            )
        );
    }

    function ModelRow(props) {
        const model = props.model;
        const caps = Array.isArray(model.capabilities) ? model.capabilities : [];
        const options = props.capabilities.length ? props.capabilities : [
            { label: '文本', value: 'text_generation' },
            { label: '视觉', value: 'vision' },
            { label: '生图', value: 'image_generation' },
        ];

        function toggleCapability(value, checked) {
            let next = caps.slice();
            if (checked && next.indexOf(value) === -1) {
                next.push(value);
            }
            if (!checked) {
                next = next.filter(function (item) { return item !== value; });
            }
            if (value === 'image_generation' && checked) {
                next = ['image_generation'];
            } else if (checked) {
                next = next.filter(function (item) { return item !== 'image_generation'; });
            }
            props.updateModel(props.relayIndex, props.modelIndex, { capabilities: next });
        }

        return h('div', { className: 'chuyi-ai-relay-model-row' },
            h(TextControl, {
                label: '模型 ID',
                value: model.id || '',
                onChange: function (id) { props.updateModel(props.relayIndex, props.modelIndex, { id: id }); },
            }),
            h(TextControl, {
                label: '显示名称',
                value: model.name || '',
                onChange: function (name) { props.updateModel(props.relayIndex, props.modelIndex, { name: name }); },
            }),
            h(Button, { variant: 'tertiary', isDestructive: true, onClick: function () { props.removeModel(props.relayIndex, props.modelIndex); } }, '删除'),
            h('div', { className: 'chuyi-ai-relay-capabilities' }, options.map(function (option) {
                return h(CheckboxControl, {
                    key: option.value,
                    label: option.label || capabilityLabel(option.value),
                    checked: caps.indexOf(option.value) !== -1,
                    onChange: function (checked) { toggleCapability(option.value, checked); },
                });
            }))
        );
    }

    if (wp.element.createRoot) {
        wp.element.createRoot(rootNode).render(h(App));
    } else {
        wp.element.render(h(App), rootNode);
    }
}(window, document, window.wp));