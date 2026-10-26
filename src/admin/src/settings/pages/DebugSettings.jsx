import {
    Button,
    Card,
    CardBody,
    CardHeader,
    __experimentalSpacer as Spacer,
    Notice,
    Animate,
    TextControl, ToggleControl, TextareaControl,
} from "@wordpress/components";
import {useContext, useEffect, useState} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {SettingsContext} from "../context/SettingsContext";

const {__} = wp.i18n;

function DebugSettings() {
    const {
        settings,
        updateSetting,
        saveSettings,
        settingsSaved,
        setSettingsSaved,
        isPro,
        isStudio
    } = useContext(SettingsContext);
    const [activateDebugLog, setActivateDebugLog] = useState(false);
    const [useServerCron, setUserServerCron] = useState(false);
    const [clearingTemp, setClearingTemp] = useState(false);
    const [clearNotice, setClearNotice] = useState(null);

    const handleClearTemp = async () => {
        setClearingTemp(true);
        setClearNotice(null);
        try {
            const resp = await apiFetch({path: '/wp-static-export/v1/clear-temp-files', method: 'POST'});
            let data = resp;
            // Some endpoints in this app return JSON string, some parsed.
            if (typeof resp === 'string') {
                try {
                    data = JSON.parse(resp);
                } catch (e) { /* ignore */
                }
            }
            if (data && data.status === 200) {
                setClearNotice({type: 'success', message: __('Temporary files cleared.', 'wp-static-export')});
            } else {
                const msg = (data && data.message) ? data.message : __('Could not clear temporary files.', 'wp-static-export');
                setClearNotice({type: 'error', message: msg});
            }
        } catch (e) {
            setClearNotice({type: 'error', message: __('Request failed. Please try again.', 'wp-static-export')});
        } finally {
            setClearingTemp(false);
        }
    };

    const setSavingSettings = () => {
        saveSettings();
        setSettingsSaved(true);

        setTimeout(function () {
            setSettingsSaved(false);
        }, 2000);
    }

    useEffect(() => {
        if (settings.debugging_mode) {
            setActivateDebugLog(settings.debugging_mode);
        }

        if (settings.server_cron) {
            setUserServerCron(settings.server_cron);
        }
    }, [settings]);

    return (<div className={"inner-settings"}>
        <Card>
            <CardHeader>
                <b>{__('Basic Auth', 'wp-static-export')}</b>
            </CardHeader>
            <CardBody>
                <p>
                    {__('If you\'ve secured WordPress with HTTP Basic Auth you need to specify the username and password to use below.', 'wp-static-export')}
                </p>
                <TextControl
                    label={__('Basic Auth Username', 'wp-static-export')}
                    autoComplete={"off"}
                    type={"text"}
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                    value={settings.http_basic_auth_username}
                    onChange={(username) => {
                        updateSetting('http_basic_auth_username', username);
                    }}
                />
                <TextControl
                    label={__('Basic Auth Password', 'wp-static-export')}
                    type={"password"}
                    autoComplete={"off"}
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                    value={settings.http_basic_auth_password}
                    onChange={(username) => {
                        updateSetting('http_basic_auth_password', username);
                    }}
                />
                <ToggleControl
                    label={__('Enable Basic Auth', 'wp-static-export')}
                    __nextHasNoMarginBottom
                    help={
                        <>
                            {'free' === options.plan ?
                                <>
                                    {__('Automatic Basic Auth setup is not available in this version.', 'wp-static-export')}
                                </>
                                :
                                <>
                                    {__('Once enabled we will put your entire website behind password protection.', 'wp-static-export')}
                                </>
                            }
                        </>
                    }
                    disabled={('free' === options.plan || !isPro())}
                    checked={!!settings.http_basic_auth_on}
                    onChange={(value) => {
                        updateSetting('http_basic_auth_on', value);
                    }}
                />
                {
                    settings.http_basic_auth_on &&
                    (!settings.http_basic_auth_username || !settings.http_basic_auth_password) &&
                    <Notice status={"warning"} isDismissible={false}>
                        {__('Requires Username & Password to work', 'wp-static-export')}
                    </Notice>
                }
            </CardBody>
        </Card>
        <Spacer margin={5}/>
        <Card>
            <CardHeader>
                <b>{__('Temporary Files', 'wp-static-export')}</b>
            </CardHeader>
            <CardBody>
                <TextControl
                    label={__('Temporary Files Directory', 'wp-static-export')}
                    type={"text"}
                    placeholder={options.temp_files_dir}
                    help={__('Optionally specify the directory to save your temporary files. This directory must exist and be writable.', 'wp-static-export')}
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                    disabled={isStudio()}
                    value={settings.temp_files_dir}
                    onChange={(temp_dir) => {
                        updateSetting('temp_files_dir', temp_dir);
                    }}
                />

                {clearNotice && (
                    <>
                        <Notice status={clearNotice.type} isDismissible={true}
                                onRemove={() => setClearNotice(null)}>
                            {clearNotice.message}
                        </Notice>
                        <Spacer margin={5}/>
                    </>
                )}

                <Button
                    isSecondary
                    onClick={handleClearTemp}
                    disabled={isStudio() || clearingTemp}
                    isBusy={clearingTemp}
                >
                    {clearingTemp ? __('Clearing…', 'wp-static-export') : __('Clear Temporary Files', 'wp-static-export')}
                </Button>
            </CardBody>
        </Card>
        <Spacer margin={5}/>
        <Card>
            <CardHeader>
                <b>{__('Whitelist Plugins', 'wp-static-export')}</b>
            </CardHeader>
            <CardBody>
                <TextareaControl
                    label={__('Whitelist plugins in diagnostics', 'wp-static-export')}
                    placeholder={"autoptimize\nwp-search-with-algolia\nwp-rocket"}
                    help={__('If you want to exclude certain plugins from the diagnostics check add the plugin slugs here (one per line).', 'wp-static-export')}
                    __nextHasNoMarginBottom
                    value={settings.whitelist_plugins}
                    onChange={(value) => {
                        updateSetting('whitelist_plugins', value);
                    }}
                />
            </CardBody>
        </Card>
        <Spacer margin={5}/>
        <Card>
            <CardHeader>
                <b>{__('Proxy Setup', 'wp-static-export')}</b>
            </CardHeader>
            <CardBody>
                <TextControl
                    label={__('Origin URL', 'wp-static-export')}
                    type={"url"}
                    help={__('If the URL of your WordPress installation differs from the public-facing URL (Proxy Setup), add the public URL here.', 'wp-static-export')}
                    placeholder={options.home}
                    autoComplete={"off"}
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                    disabled={isStudio()}
                    value={settings.origin_url}
                    onChange={(origin_url) => {
                        updateSetting('origin_url', origin_url);
                    }}
                />
            </CardBody>
        </Card>
        <Spacer margin={5}/>
        <Card>
            <CardHeader>
                <b>{__('Debug Log', 'wp-static-export')}</b>
            </CardHeader>
            <CardBody>
                <ToggleControl
                    label={__('Activate Debug Log', 'wp-static-export')}
                    help={__('Enable it to download the debug log from WP Static Export -> Generate.', 'wp-static-export')}
                    __nextHasNoMarginBottom
                    checked={activateDebugLog}
                    onChange={(value) => {
                        setActivateDebugLog(value);
                        updateSetting('debugging_mode', value);
                    }}
                />
            </CardBody>
        </Card>
        <Spacer margin={5}/>
        <Card>
            <CardHeader>
                <b>{__('Cron', 'wp-static-export')}</b>
            </CardHeader>
            <CardBody>
                <ToggleControl
                    label={__('Use server-side cron job', 'wp-static-export')}
                    help={__('Enable this if you use a server-side cron job instead of the default WP-Cron.', 'wp-static-export')}
                    __nextHasNoMarginBottom
                    disabled={isStudio()}
                    checked={useServerCron}
                    onChange={(value) => {
                        setUserServerCron(value);
                        updateSetting('server_cron', value);
                    }}
                />
            </CardBody>
        </Card>
        <Spacer margin={5}/>
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
            <Button onClick={setSavingSettings}
                    variant="primary">{__('Save Settings', 'wp-static-export')}</Button>
        </div>
    </div>)
}

export default DebugSettings;
