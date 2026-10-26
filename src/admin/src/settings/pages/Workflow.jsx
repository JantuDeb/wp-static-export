import {
    Card,
    CardBody,
    CardHeader,
    __experimentalSpacer as Spacer,
    FormTokenField,
    ToggleControl,
    Button,
    Notice,
    Animate,
    TextControl,
    __experimentalInputControl as InputControl,
    Flex,
    FlexItem,
    ExternalLink,
} from '@wordpress/components';
import {Fragment, useContext, useEffect, useMemo, useState} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {SettingsContext} from "../context/SettingsContext";

const {__} = wp.i18n;

function Workflow() {
    const {settings, updateSetting, saveSettings, settingsSaved, setSettingsSaved, isPro} = useContext(SettingsContext);
    const proEnabled = typeof isPro === 'function' ? !!isPro() : true;

    // Local state for token field (labels shown in the UI)
    const [pageSuggestions, setPageSuggestions] = useState([]); // array of labels
    const [pagesMap, setPagesMap] = useState({}); // label -> ID
    const [selectedPageLabels, setSelectedPageLabels] = useState([]); // labels shown as tokens

    // Taxonomies (for taxonomy archives FormTokenField)
    const [taxonomySuggestions, setTaxonomySuggestions] = useState([]); // array of labels
    const [taxonomyMap, setTaxonomyMap] = useState({}); // label -> slug
    const [selectedTaxonomyLabels, setSelectedTaxonomyLabels] = useState([]);

    // Master toggle and sitemap toggle
    const [useSingleExports, setUseSingleExports] = useState(true);
    const [updateXmlSitemap, setUpdateXmlSitemap] = useState(false);
    // Auto export controls
    const [autoExport, setAutoExport] = useState(false);
    const [autoExportDelay, setAutoExportDelay] = useState(3);
    // Webhook settings (moved to dedicated card)
    const [webhookUrl, setWebhookUrl] = useState('');
    const [webhookEnabledTypes, setWebhookEnabledTypes] = useState(['export', 'update', 'build', 'single']);

    const [incArchives, setIncArchives] = useState(true);
    const [incPagination, setIncPagination] = useState(true);

    // Builds toggle
    const [useBuilds, setUseBuilds] = useState(false);

    // Tools submenu toggle
    const [toolsSubmenu, setToolsSubmenu] = useState(false);

    // Post Types for Auto Export selection
    const [postTypes, setPostTypes] = useState([]);
    const [postTypeSuggestions, setPostTypeSuggestions] = useState([]); // array of labels
    const [postTypeMap, setPostTypeMap] = useState({}); // label -> name (slug)
    const [selectedPostTypeLabels, setSelectedPostTypeLabels] = useState([]);

    // Load pages suggestions (published pages list) and taxonomies
    useEffect(() => {
        // Initialize master and sitemap toggles from settings
        setUseSingleExports(settings.ss_use_single_exports !== false);
        setUpdateXmlSitemap(!!settings.ss_single_export_add_xml_sitemap);

        // Pages
        apiFetch({path: '/wp-static-export/v1/pages'}).then((list) => {
            const labels = list.map(i => i.label);
            const map = {};
            list.forEach(i => map[i.label] = i.value);
            setPageSuggestions(labels);
            setPagesMap(map);

            // Initialize tokens from settings (IDs -> labels)
            const ids = Array.isArray(settings.ss_single_pages) ? settings.ss_single_pages : [];
            if (ids.length > 0) {
                const toLabels = ids.map(id => {
                    const entry = list.find(i => String(i.value) === String(id));
                    return entry ? entry.label : String(id);
                });
                setSelectedPageLabels(toLabels);
            }
        });

        // Taxonomies
        apiFetch({path: '/wp-static-export/v1/taxonomies'}).then((list) => {
            // list: [{label, value: slug}]
            const labels = list.map(i => i.label);
            const map = {};
            list.forEach(i => map[i.label] = i.value);
            setTaxonomySuggestions(labels);
            setTaxonomyMap(map);

            // Initialize taxonomy tokens from settings (slugs -> labels)
            const slugs = Array.isArray(settings.ss_single_taxonomy_archives) ? settings.ss_single_taxonomy_archives : [];
            if (slugs.length > 0) {
                const toLabels = slugs.map(slug => {
                    const entry = list.find(i => String(i.value) === String(slug));
                    return entry ? entry.label : String(slug);
                });
                setSelectedTaxonomyLabels(toLabels);
            }
        });

        // Post types (for Auto Export selection)
        apiFetch({path: '/wp-static-export/v1/post-types', parse: true}).then((resp) => {
            if (typeof resp === 'string') {
                try {
                    resp = JSON.parse(resp);
                } catch (e) {
                    setPostTypes([]);
                    return;
                }
            }

            // API returns {status, data: [{name, label}]}
            let list = Array.isArray(resp) ? resp : (resp && resp.data ? resp.data : []);
            setPostTypes(list);
        });

        // Initialize toggles from settings; coerce truthiness properly
        setIncArchives(!!settings.ss_single_include_archives || settings.ss_single_include_archives === undefined);
        setIncPagination(!!settings.ss_single_include_pagination || settings.ss_single_include_pagination === undefined);
        // Initialize Builds toggle (default to false when undefined)
        setUseBuilds(!!settings.ss_use_builds);
        // Initialize Tools submenu toggle
        setToolsSubmenu(!!settings.ss_tools_submenu);
        // Auto export and delay
        setAutoExport(!!settings.ss_single_auto_export);
        if (typeof settings.ss_single_auto_export_delay !== 'undefined') {
            const d = parseInt(settings.ss_single_auto_export_delay, 10);
            setAutoExportDelay(Number.isNaN(d) ? 3 : Math.max(0, d));
        } else {
            setAutoExportDelay(3);
        }
        // Webhook settings (new)
        setWebhookUrl(settings.ss_webhook_url || settings.ss_single_export_webhook_url || '');
        const defaultTypes = ['export', 'update', 'build', 'single'];
        if (Array.isArray(settings.ss_webhook_enabled_types) && settings.ss_webhook_enabled_types.length) {
            setWebhookEnabledTypes(settings.ss_webhook_enabled_types);
        } else {
            // If legacy single-only webhook existed, default to single only; else enable all.
            setWebhookEnabledTypes((settings.ss_single_export_webhook_url && settings.ss_single_export_webhook_url.length) ? ['single'] : defaultTypes);
        }
    }, [settings.ss_single_pages, settings.ss_single_taxonomy_archives, settings.ss_use_builds]);

    useEffect(() => {
        if (!postTypes.length) {
            setPostTypeSuggestions([]);
            setPostTypeMap({});
            return;
        }

        const labels = postTypes.map(i => i.label);
        const map = {};
        postTypes.forEach(i => map[i.label] = i.name);
        setPostTypeSuggestions(labels);
        setPostTypeMap(map);

        // Empty means all public post types. Keep the field visually empty so
        // suggestions remain available after a user clears and saves it.
        const saved = Array.isArray(settings.ss_single_auto_export_types) ? settings.ss_single_auto_export_types : [];
        const validSaved = saved.filter(slug => postTypes.some(i => String(i.name) === String(slug)));
        const toLabels = validSaved.map(slug => {
            const entry = postTypes.find(i => String(i.name) === String(slug));
            return entry.label;
        });
        setSelectedPostTypeLabels(toLabels);
        if (Array.isArray(settings.ss_single_auto_export_types) && saved.length !== validSaved.length) {
            updateSetting('ss_single_auto_export_types', validSaved);
        }
    }, [postTypes, settings.ss_single_auto_export_types]);

    // Handlers
    const onChangeTokens = (tokens) => {
        if (!proEnabled) return;
        setSelectedPageLabels(tokens);
        // Map labels back to IDs using pagesMap; keep unknown tokens as-is if numeric
        const ids = tokens.map(label => {
            if (pagesMap[label]) return pagesMap[label];
            // If label is a number string, keep as number
            const n = parseInt(label, 10);
            return isNaN(n) ? null : n;
        }).filter(v => v !== null);
        updateSetting('ss_single_pages', ids);
    };

    // Handlers for taxonomy token field
    const onChangeTaxonomyTokens = (tokens) => {
        if (!proEnabled) return;
        setSelectedTaxonomyLabels(tokens);
        const slugs = tokens.map(label => {
            if (taxonomyMap[label]) return taxonomyMap[label];
            // Fall back to using the raw token as slug if it's a known slug present in suggestions map values
            return label;
        }).filter(Boolean);
        updateSetting('ss_single_taxonomy_archives', slugs);
    };

    // Handlers for post type selection token field
    const onChangePostTypeTokens = (tokens) => {
        if (!proEnabled) return;
        const validSlugs = Object.values(postTypeMap);
        const slugs = tokens.map(label => {
            if (postTypeMap[label]) {
                return postTypeMap[label];
            }

            return validSlugs.includes(label) ? label : null;
        }).filter(Boolean);
        const labels = slugs.map(slug => {
            const entry = Object.entries(postTypeMap).find(([, value]) => value === slug);
            return entry ? entry[0] : slug;
        });

        setSelectedPostTypeLabels(labels);
        updateSetting('ss_single_auto_export_types', slugs);
    };

    return (
        <div className={"inner-settings"}>
            <Card>
                <CardHeader>
                    <Flex>
                        <FlexItem>
                            <strong>{__('Single Push', 'wp-static-export')}</strong>
                        </FlexItem>
                    </Flex>
                </CardHeader>
                <CardBody>
                    <ToggleControl
                        label={__('Use Single Push?', 'wp-static-export')}
                        help={useSingleExports
                            ? __('Quickly push updates to your static site while editing pages/posts.', 'wp-static-export')
                            : __('Do not allow quick updates while editing posts/pages.', 'wp-static-export')}
                        checked={!!useSingleExports}
                        disabled={!proEnabled}
                        onChange={(value) => {
                            if (!proEnabled) return;
                            setUseSingleExports(value);
                            updateSetting('ss_use_single_exports', value);
                        }}
                    />
                    {useSingleExports && (
                        <>
                            <ToggleControl
                                label={__('Use Auto Push?', 'wp-static-export')}
                                help={autoExport
                                    ? __('Automatically schedule a Single Push when a post/page is updated/published.', 'wp-static-export')
                                    : __('Do not automatically run Single Push when a post/page is updated/published.', 'wp-static-export')}
                                checked={!!autoExport}
                                disabled={!proEnabled}
                                onChange={(value) => {
                                    if (!proEnabled) return;
                                    setAutoExport(value);
                                    updateSetting('ss_single_auto_export', value);
                                }}
                            />
                            {autoExport && (
                                <>
                                    <FormTokenField
                                        label={__('Auto Push Post Types', 'wp-static-export')}
                                        __next40pxDefaultSize
                                        value={selectedPostTypeLabels}
                                        suggestions={postTypeSuggestions}
                                        onChange={onChangePostTypeTokens}
                                        help={__('Choose which public post types should trigger automatic single pushes when updated. If none is selected, all public post types are used.', 'wp-static-export')}
                                        tokenizeOnSpace={false}
                                        __experimentalExpandOnFocus={true}
                                        __experimentalShowHowTo={false}
                                        maxSuggestions={100}
                                        className="horizontal-token-field"
                                        disabled={!proEnabled}
                                    />
                                    <p className={'description'}>
                                        {__('Choose which public post types should trigger automatic single pushes when updated. If none is selected, all public post types are used.', 'wp-static-export')}
                                    </p>
                                </>
                            )}
                            {autoExport && (
                                <InputControl
                                    label={__('Auto push delay (seconds)', 'wp-static-export')}
                                    help={__('Delay before the automatic Single Push starts after a change is detected.', 'wp-static-export')}
                                    type="number"
                                    min={0}
                                    value={autoExportDelay}
                                    disabled={!proEnabled}
                                    onChange={(value) => {
                                        if (!proEnabled) return;
                                        const n = parseInt(value, 10);
                                        const safe = Number.isNaN(n) ? 0 : Math.max(0, n);
                                        setAutoExportDelay(safe);
                                        updateSetting('ss_single_auto_export_delay', safe);
                                    }}
                                />
                            )}
                            <FormTokenField
                                label={__('Pages to update', 'wp-static-export')}
                                __next40pxDefaultSize
                                __nextHasNoMarginBottom
                                value={selectedPageLabels}
                                suggestions={pageSuggestions}
                                onChange={onChangeTokens}
                                help={__('Select which pages should start a Single Push. If none selected, we use your homepage.', 'wp-static-export')}
                                tokenizeOnSpace={false}
                                __experimentalExpandOnFocus={true}
                                __experimentalShowHowTo={false}
                                maxSuggestions={200}
                                className="horizontal-token-field"
                                disabled={!proEnabled}
                            />
                            <p className={'description'}>
                                {__('Choose which pages/files should be updated when running a Single Push.', 'wp-static-export')}
                            </p>
                            <Spacer margin={2}/>
                            <FormTokenField
                                label={__('Taxonomy Archives', 'wp-static-export')}
                                __next40pxDefaultSize
                                __nextHasNoMarginBottom
                                value={selectedTaxonomyLabels}
                                suggestions={taxonomySuggestions}
                                onChange={onChangeTaxonomyTokens}
                                help={__('Choose which taxonomy archives to include for selected pages/posts (e.g., Categories, Tags, or custom taxonomies).', 'wp-static-export')}
                                tokenizeOnSpace={false}
                                __experimentalExpandOnFocus={true}
                                __experimentalShowHowTo={false}
                                maxSuggestions={100}
                                className="horizontal-token-field"
                                disabled={!proEnabled}
                            />
                            <p className={'description'}>
                                {__('Choose which taxonomies should be updated when running a Single Push.', 'wp-static-export')}
                            </p>
                            <Spacer margin={2}/>
                            <ToggleControl
                                label={__('Update archives (author/date/post_type)', 'wp-static-export')}
                                help={incArchives
                                    ? __('Update archive URLs when running a Single Push.', 'wp-static-export')
                                    : __('Do not update archive URLs.', 'wp-static-export')}
                                checked={!!incArchives}
                                disabled={!proEnabled}
                                onChange={(value) => {
                                    if (!proEnabled) return;
                                    setIncArchives(value);
                                    updateSetting('ss_single_include_archives', value);
                                }}
                            />
                            <ToggleControl
                                label={__('Update pagination', 'wp-static-export')}
                                help={incPagination
                                    ? __('Update pagination URLs when running a Single Push.', 'wp-static-export')
                                    : __('Do not update pagination URLs.', 'wp-static-export')}
                                checked={!!incPagination}
                                disabled={!proEnabled}
                                onChange={(value) => {
                                    if (!proEnabled) return;
                                    setIncPagination(value);
                                    updateSetting('ss_single_include_pagination', value);
                                }}
                            />
                            <ToggleControl
                                label={__('Update XML sitemap', 'wp-static-export')}
                                help={updateXmlSitemap
                                    ? __('Update XML sitemaps when running a Single Push.', 'wp-static-export')
                                    : __('Do not update XML sitemaps.', 'wp-static-export')}
                                checked={!!updateXmlSitemap}
                                disabled={!proEnabled}
                                onChange={(value) => {
                                    if (!proEnabled) return;
                                    setUpdateXmlSitemap(value);
                                    updateSetting('ss_single_export_add_xml_sitemap', value);
                                }}
                            />
                        </>
                    )}
                </CardBody>
            </Card>
            <Spacer margin={5}/>
            <Card>
                <CardHeader>
                    <Flex>
                        <FlexItem>
                            <strong>{__('Builds', 'wp-static-export')}</strong>
                        </FlexItem>
                    </Flex>
                </CardHeader>
                <CardBody>
                    <ToggleControl
                        label={__('Enable Builds', 'wp-static-export')}
                        help={useBuilds
                            ? __('Use builds to quickly update a list of pages and files on your static site.', 'wp-static-export')
                            : __('Builds are disabled.', 'wp-static-export')}
                        checked={!!useBuilds}
                        disabled={!proEnabled}
                        onChange={(value) => {
                            if (!proEnabled) return;
                            setUseBuilds(value);
                            updateSetting('ss_use_builds', value);
                        }}
                    />
                </CardBody>
            </Card>
            <Spacer margin={5}/>
            <Card>
                <CardHeader>
                    <Flex>
                        <FlexItem>
                            <strong>{__('Tools Submenu', 'wp-static-export')}</strong>
                        </FlexItem>
                    </Flex>
                </CardHeader>
                <CardBody>
                    <ToggleControl
                        label={__('Move menu to Tools submenu', 'wp-static-export')}
                        help={toolsSubmenu
                            ? __('The WP Static Export menu is placed under the Tools menu in the admin sidebar.', 'wp-static-export')
                            : __('The WP Static Export menu is shown as a top-level item in the admin sidebar.', 'wp-static-export')}
                        checked={!!toolsSubmenu}
                        disabled={!proEnabled}
                        onChange={(value) => {
                            if (!proEnabled) return;
                            setToolsSubmenu(value);
                            updateSetting('ss_tools_submenu', value);
                        }}
                    />
                </CardBody>
            </Card>
            <Spacer margin={5}/>
            <Card>
                <CardHeader>
                    <Flex>
                        <FlexItem>
                            <strong>{__('Webhook', 'wp-static-export')}</strong>
                        </FlexItem>
                    </Flex>
                </CardHeader>
                <CardBody>
                    <TextControl
                        label={__('Webhook URL', 'wp-static-export')}
                        placeholder={'https://example.com/webhook-endpoint'}
                        value={webhookUrl}
                        disabled={!proEnabled}
                        onChange={(value) => {
                            if (!proEnabled) return;
                            setWebhookUrl(value);
                            updateSetting('ss_webhook_url', value);
                        }}
                        help={__('WP Static Export will POST a JSON payload to this URL after pushes finish.', 'wp-static-export')}
                    />
                    <p className={'description'}>{__('Fire for these push types:', 'wp-static-export')}</p>
                    <div className="ss-webhook-types">
                        {['export', 'update', 'build', 'single'].map((type) => (
                            <ToggleControl
                                key={type}
                                label={
                                    type === 'export' ? __('Full Site', 'wp-static-export') :
                                        type === 'update' ? __('Update', 'wp-static-export') :
                                            type === 'build' ? __('Build', 'wp-static-export') : __('Single', 'wp-static-export')
                                }
                                checked={webhookEnabledTypes.includes(type)}
                                disabled={!proEnabled}
                                onChange={(checked) => {
                                    if (!proEnabled) return;
                                    const next = new Set(webhookEnabledTypes);
                                    if (checked) next.add(type); else next.delete(type);
                                    const arr = Array.from(next);
                                    setWebhookEnabledTypes(arr);
                                    updateSetting('ss_webhook_enabled_types', arr);
                                }}
                            />
                        ))}
                    </div>
                    <p className={'description'}>
                        {__('Payload includes event, site_url, export_type, identifiers (when available), timestamp, and success.', 'wp-static-export')}
                    </p>
                </CardBody>
            </Card>
            {settingsSaved && (
                <>
                    <Animate type="slide-in" options={{origin: 'top'}}>
                        {() => (
                            <Notice status="success" isDismissible={false}>
                                <p>
                                    {__('Settings saved successfully.', 'wp-static-export')}
                                </p>
                            </Notice>
                        )}
                    </Animate>
                    <Spacer margin={5}/>
                </>
            )}

            {proEnabled && (
                <div className="save-settings">
                    <Button
                        onClick={() => {
                            if (!proEnabled) return;
                            saveSettings();
                            setSettingsSaved(true);
                            setTimeout(() => setSettingsSaved(false), 2000);
                        }}
                        variant="primary"
                    >
                        {__('Save Settings', 'wp-static-export')}
                    </Button>
                </div>
            )}
        </div>
    );
}

export default Workflow;
