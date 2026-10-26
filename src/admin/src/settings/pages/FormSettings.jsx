import {
    Button,
    Card,
    CardBody,
    CardHeader,
    __experimentalSpacer as Spacer,
    Notice,
    Animate,
    TextControl, SelectControl, ToggleControl, TextareaControl, Flex, FlexItem, ExternalLink,
} from "@wordpress/components";
import {useContext, useEffect, useState} from '@wordpress/element';
import {SettingsContext} from "../context/SettingsContext";
import apiFetch from "@wordpress/api-fetch";

const {__} = wp.i18n;

function FormSettings() {
    const {
        settings,
        updateSetting,
        saveSettings,
        settingsSaved,
        setSettingsSaved,
        isPro
    } = useContext(SettingsContext);
    const [useForms, setUseForms] = useState(false);
    const [useFormConditionalLoading, setUseFormConditionalLoading] = useState(false);
    const [useComments, setUseComments] = useState(false);
    const [saveFormEntries, setSaveFormEntries] = useState(true);
    const [pagesSlugs, setPagesSlugs] = useState(false);
    const [detectingCredentials, setDetectingCredentials] = useState(false);
    const [detectedCredentials, setDetectedCredentials] = useState(null);
    const [credentialsNotice, setCredentialsNotice] = useState(null);

    const setSavingSettings = () => {
        saveSettings();
        setSettingsSaved(true);

        setTimeout(function () {
            setSettingsSaved(false);

            if (useForms) {
                localStorage.setItem('ss-initial-page', '/forms');
                window.location.reload();
            }
        }, 2000);
    }

    const getPages = () => {
        apiFetch({path: '/wp-static-export/v1/pages-slugs'}).then((fetched_pages) => {
            let pages = fetched_pages;

            pages.unshift({label: __('No page selected', 'wp-static-export'), value: ''});
            setPagesSlugs(pages);
        });
    }

    const detectExistingCredentials = () => {
        setDetectingCredentials(true);
        setCredentialsNotice(null);
        
        apiFetch({path: '/wp-static-export/v1/captcha/detect-credentials'})
            .then((response) => {
                setDetectingCredentials(false);
                
                if (!response.success) {
                    setCredentialsNotice({
                        type: 'error',
                        message: __('Failed to detect credentials.', 'wp-static-export')
                    });
                    return;
                }
                
                const credentials = response.credentials;
                const currentService = settings.captcha_service || '';
                const recaptchaCredentials = credentials.recaptcha
                    ? credentials.recaptcha.filter((credential) => {
                        // Older Pro versions did not expose a service marker and
                        // only detected reCAPTCHA v3 credentials.
                        const service = credential.service || 'recaptcha_v3';
                        return service === currentService;
                    })
                    : [];
                
                // Check for credentials based on current service
                let foundCredentials = null;
                let sourceName = '';
                
                if (currentService === 'turnstile' && credentials.turnstile && credentials.turnstile.length > 0) {
                    foundCredentials = credentials.turnstile[0];
                    sourceName = foundCredentials.source;
                    
                    // Apply Turnstile credentials
                    updateSetting('cloudflare_turnstile_site_key', foundCredentials.site_key);
                    updateSetting('cloudflare_turnstile_secret_key', foundCredentials.secret_key);
                    
                    setCredentialsNotice({
                        type: 'success',
                        message: __('Turnstile credentials copied from ', 'wp-static-export') + sourceName + '!'
                    });
                } else if ((currentService === 'recaptcha_v2' || currentService === 'recaptcha_v3') && recaptchaCredentials.length > 0) {
                    foundCredentials = recaptchaCredentials[0];
                    sourceName = foundCredentials.source;
                    
                    // Apply reCAPTCHA credentials
                    updateSetting('recaptcha_site_key', foundCredentials.site_key);
                    updateSetting('recaptcha_secret_key', foundCredentials.secret_key);
                    
                    setCredentialsNotice({
                        type: 'success',
                        message: __('reCAPTCHA credentials copied from ', 'wp-static-export') + sourceName + '!'
                    });
                } else {
                    // No credentials found for current service, check if other service has credentials
                    let alternativeMsg = '';
                    if (currentService === 'turnstile' && credentials.recaptcha && credentials.recaptcha.length > 0) {
                        alternativeMsg = __(' reCAPTCHA credentials were found - switch to the matching reCAPTCHA version to use them.', 'wp-static-export');
                    } else if ((currentService === 'recaptcha_v2' || currentService === 'recaptcha_v3') && credentials.turnstile && credentials.turnstile.length > 0) {
                        alternativeMsg = __(' Turnstile credentials were found - switch to Turnstile to use them.', 'wp-static-export');
                    } else if (currentService === 'recaptcha_v2' && credentials.recaptcha && credentials.recaptcha.length > 0) {
                        alternativeMsg = __(' reCAPTCHA v3 credentials were found - switch to reCAPTCHA v3 to use them.', 'wp-static-export');
                    } else if (currentService === 'recaptcha_v3' && credentials.recaptcha && credentials.recaptcha.length > 0) {
                        alternativeMsg = __(' reCAPTCHA v2 credentials were found - switch to reCAPTCHA v2 to use them.', 'wp-static-export');
                    }
                    
                    setCredentialsNotice({
                        type: 'warning',
                        message: __('No credentials found for the selected captcha service.', 'wp-static-export') + alternativeMsg
                    });
                }
                
                setDetectedCredentials(credentials);
            })
            .catch((error) => {
                setDetectingCredentials(false);
                setCredentialsNotice({
                    type: 'error',
                    message: __('Error detecting credentials: ', 'wp-static-export') + (error.message || __('Unknown error', 'wp-static-export'))
                });
            });
    }

    useEffect(() => {
        getPages();

        if (settings.use_forms) {
            setUseForms(settings.use_forms);
        }

        if (settings.use_comments) {
            setUseComments(settings.use_comments);
        }

        // Save Form Entries (default on)
        if (typeof settings.save_form_entries !== 'undefined') {
            setSaveFormEntries(!!settings.save_form_entries);
        } else {
            setSaveFormEntries(true);
        }

        if (typeof settings.use_form_conditional_loading !== 'undefined') {
            setUseFormConditionalLoading(!!settings.use_form_conditional_loading);
        }

    }, [settings]);

    return (<div className={"inner-settings"}>
        <Card>
            <CardHeader>
                <Flex>
                    <FlexItem>
                        <b>{__('Forms', 'wp-static-export')}</b>
                    </FlexItem>
                    {('free' === options.plan || !isPro()) &&
                        <FlexItem>
                            <a href="admin.php?page=wp-static-export-features">{__('Configure in Deploy & Features', 'wp-static-export')}</a>
                        </FlexItem>
                    }
                </Flex>
            </CardHeader>
            <CardBody>
                <ToggleControl
                    label={__('Use forms?', 'wp-static-export')}
                    __nextHasNoMarginBottom
                    help={
                        useForms
                            ? __('Use Forms on your static website.', 'wp-static-export')
                            : __('Don\'t use forms on your static website.', 'wp-static-export')
                    }
                    disabled={('free' === options.plan || !isPro())}
                    checked={useForms}
                    onChange={(value) => {
                        setUseForms(value);
                        updateSetting('use_forms', value);
                    }}
                />
                {useForms && (
                    <ToggleControl
                        label={__('Store form entries', 'wp-static-export')}
                        __nextHasNoMarginBottom
                        help={
                            saveFormEntries
                                ? __('Store form entries inside WordPress (requires WP Static Export Webhook).', 'wp-static-export')
                                : __('Do not store form entries in WordPress.', 'wp-static-export')
                        }
                        disabled={('free' === options.plan || !isPro())}
                        checked={!!saveFormEntries}
                        onChange={(value) => {
                            setSaveFormEntries(value);
                            updateSetting('save_form_entries', value);
                        }}
                    />
                )}
                {useForms && (
                    <ToggleControl
                        label={__('Enable conditional loading', 'wp-static-export')}
                        __nextHasNoMarginBottom
                        help={
                            useFormConditionalLoading
                                ? __('Form scripts are only loaded on pages that contain a connected form.', 'wp-static-export')
                                : __('Form scripts are loaded on all pages for maximum compatibility.', 'wp-static-export')
                        }
                        disabled={('free' === options.plan || !isPro())}
                        checked={useFormConditionalLoading}
                        onChange={(value) => {
                            setUseFormConditionalLoading(value);
                            updateSetting('use_form_conditional_loading', value);
                        }}
                    />
                )}
                {useForms && options.form_connection_url && 'free' !== options.plan &&
                    <Button href={options.form_connection_url}
                            variant="secondary">{__('Create a form connection', 'wp-static-export')}</Button>
                }
            </CardBody>
        </Card>
        <Spacer margin={5}/>
        <Card>
            <CardHeader>
                <Flex>
                    <FlexItem>
                        <b>{__('Comments', 'wp-static-export')}</b>
                    </FlexItem>
                    {('free' === options.plan || !isPro()) &&
                        <FlexItem>
                            <a href="admin.php?page=wp-static-export-features">{__('Configure in Deploy & Features', 'wp-static-export')}</a>
                        </FlexItem>
                    }
                </Flex>
            </CardHeader>
            <CardBody>
                <ToggleControl
                    label={__('Use comments?', 'wp-static-export')}
                    __nextHasNoMarginBottom
                    help={
                        useComments
                            ? __('Use comments on your static website.', 'wp-static-export')
                            : __('Don\'t use comments on your static website.', 'wp-static-export')
                    }
                    disabled={('free' === options.plan || !isPro())}
                    checked={useComments}
                    onChange={(value) => {
                        setUseComments(value);
                        updateSetting('use_comments', value);
                    }}
                />

                {useComments &&
                    <>
                        <SelectControl
                            label={__('Select a redirect page', 'content-protector')}
                            options={pagesSlugs}
                            help={__('The post will be regenerated after comment submission, but it might take a while, so it is good practice to redirect the visitor.', 'wp-static-export')}
                            disabled={('free' === options.plan || !isPro())}
                            value={settings.comment_redirect}
                            __next40pxDefaultSize
                            __nextHasNoMarginBottom
                            onChange={(value) => {
                                updateSetting('comment_redirect', value);
                            }}
                        />
                    </>
                }
            </CardBody>
        </Card>
        <Spacer margin={5}/>
        {(useForms || useComments) && (
            <>
                <Card>
                    <CardHeader>
                        <Flex>
                            <FlexItem>
                                <b>{__('Captcha', 'wp-static-export')}</b>
                            </FlexItem>
                            {('free' === options.plan || !isPro()) && (
                                <FlexItem>
                                    <a href="admin.php?page=wp-static-export-features">{__('Configure in Deploy & Features', 'wp-static-export')}</a>
                                </FlexItem>
                            )}
                        </Flex>
                    </CardHeader>
                    <CardBody>
                        <p>{__('We will automatically protect your forms and comments with a captcha on your static site.', 'wp-static-export')}</p>
                        <SelectControl
                            label={__('Captcha Service', 'wp-static-export')}
                            help={__('Choose which captcha service to use.', 'wp-static-export')}
                            __next40pxDefaultSize
                            __nextHasNoMarginBottom
                            disabled={('free' === options.plan || !isPro())}
                            value={settings.captcha_service || ''}
                            options={[
                                { label: __('Choose Service', 'wp-static-export'), value: '' },
                                { label: __('Cloudflare Turnstile', 'wp-static-export'), value: 'turnstile' },
                                { label: __('Google reCAPTCHA v2 (Checkbox)', 'wp-static-export'), value: 'recaptcha_v2' },
                                { label: __('Google reCAPTCHA v3', 'wp-static-export'), value: 'recaptcha_v3' },
                            ]}
                            onChange={(value) => updateSetting('captcha_service', value)}
                        />
                        <Spacer margin={3}/>
                        {credentialsNotice && (
                            <Animate type="slide-in" options={{origin: 'top'}}>
                                {() => (
                                    <>
                                        <Notice
                                            status={credentialsNotice.type}
                                            isDismissible={true}
                                            onRemove={() => setCredentialsNotice(null)}
                                        >
                                            {credentialsNotice.message}
                                        </Notice>
                                        <Spacer margin={3}/>
                                    </>
                                )}
                            </Animate>
                        )}
                        <Button
                            variant="secondary"
                            disabled={('free' === options.plan || !isPro()) || detectingCredentials}
                            isBusy={detectingCredentials}
                            onClick={detectExistingCredentials}
                        >
                            {detectingCredentials 
                                ? __('Detecting...', 'wp-static-export') 
                                : __('Copy existing Credentials', 'wp-static-export')}
                        </Button>
                        <p className="components-base-control__help">
                            {__('Automatically detect and copy captcha credentials from form plugins like Contact Form 7, WPForms, or Fluent Forms.', 'wp-static-export')}
                        </p>
                        <Spacer margin={3}/>
                        {settings.captcha_service === 'turnstile' && (
                            <>
                                <TextControl
                                    label={__('Site Key', 'wp-static-export')}
                                    help={__('Your public key will be used on the static site.', 'wp-static-export')}
                                    placeholder={__('Enter your Turnstile site key', 'wp-static-export')}
                                    __next40pxDefaultSize
                                    __nextHasNoMarginBottom
                                    disabled={('free' === options.plan || !isPro())}
                                    value={settings.cloudflare_turnstile_site_key || ''}
                                    onChange={(value) => updateSetting('cloudflare_turnstile_site_key', value)}
                                />
                                <Spacer margin={3}/>
                                <TextControl
                                    label={__('Secret Key', 'wp-static-export')}
                                    help={__('Your secret key will be stored in WordPress.', 'wp-static-export')}
                                    placeholder={__('Enter your Turnstile secret key', 'wp-static-export')}
                                    type={'password'}
                                    __next40pxDefaultSize
                                    __nextHasNoMarginBottom
                                    disabled={('free' === options.plan || !isPro())}
                                    value={settings.cloudflare_turnstile_secret_key || ''}
                                    onChange={(value) => updateSetting('cloudflare_turnstile_secret_key', value)}
                                />
                                <Spacer margin={3}/>
                                <SelectControl
                                    label={__('Theme', 'wp-static-export')}
                                    help={__('Choose how the widget should look on your site.', 'wp-static-export')}
                                    __next40pxDefaultSize
                                    __nextHasNoMarginBottom
                                    disabled={('free' === options.plan || !isPro())}
                                    value={settings.cloudflare_turnstile_theme || 'auto'}
                                    options={[
                                        { label: __('Auto', 'wp-static-export'), value: 'auto' },
                                        { label: __('Light', 'wp-static-export'), value: 'light' },
                                        { label: __('Dark', 'wp-static-export'), value: 'dark' },
                                    ]}
                                    onChange={(value) => updateSetting('cloudflare_turnstile_theme', value)}
                                />
                                <Spacer margin={3}/>
                                <SelectControl
                                    label={__('Size', 'wp-static-export')}
                                    help={__('Choose the widget size.', 'wp-static-export')}
                                    __next40pxDefaultSize
                                    __nextHasNoMarginBottom
                                    disabled={('free' === options.plan || !isPro())}
                                    value={settings.cloudflare_turnstile_size || 'normal'}
                                    options={[
                                        { label: __('Normal', 'wp-static-export'), value: 'normal' },
                                        { label: __('Flexible', 'wp-static-export'), value: 'flexible' },
                                        { label: __('Compact', 'wp-static-export'), value: 'compact' },
                                    ]}
                                    onChange={(value) => updateSetting('cloudflare_turnstile_size', value)}
                                />
                            </>
                        )}
                        {(settings.captcha_service === 'recaptcha_v2' || settings.captcha_service === 'recaptcha_v3') && (
                            <>
                                <TextControl
                                    label={__('Site Key', 'wp-static-export')}
                                    help={__('Your public key will be used on the static site.', 'wp-static-export')}
                                    placeholder={__('Enter your reCAPTCHA site key', 'wp-static-export')}
                                    __next40pxDefaultSize
                                    __nextHasNoMarginBottom
                                    disabled={('free' === options.plan || !isPro())}
                                    value={settings.recaptcha_site_key || ''}
                                    onChange={(value) => updateSetting('recaptcha_site_key', value)}
                                />
                                <Spacer margin={3}/>
                                <TextControl
                                    label={__('Secret Key', 'wp-static-export')}
                                    help={__('Your secret key will be stored in WordPress.', 'wp-static-export')}
                                    placeholder={__('Enter your reCAPTCHA secret key', 'wp-static-export')}
                                    type={'password'}
                                    __next40pxDefaultSize
                                    __nextHasNoMarginBottom
                                    disabled={('free' === options.plan || !isPro())}
                                    value={settings.recaptcha_secret_key || ''}
                                    onChange={(value) => updateSetting('recaptcha_secret_key', value)}
                                />
                            </>
                        )}
                    </CardBody>
                </Card>
                <Spacer margin={5}/>
            </>
        )}

        {settingsSaved &&
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
        }
        <div className={"save-settings"}>
            {'pro' === options.plan && isPro() &&
                <Button onClick={setSavingSettings}
                        variant="primary">{__('Save Settings', 'wp-static-export')}</Button>
            }
        </div>
    </div>)
}

export default FormSettings;
