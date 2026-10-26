import {
    Button,
    ClipboardButton,
    Card,
    CardBody,
    CardHeader,
    __experimentalSpacer as Spacer,
    Notice,
    Animate,
    TextControl, SelectControl, ToggleControl, FlexItem, Flex, ExternalLink, TextareaControl
} from "@wordpress/components";
import {useContext, useEffect, useState} from '@wordpress/element';
import {SettingsContext} from "../context/SettingsContext";
import apiFetch from "@wordpress/api-fetch";

const {__} = wp.i18n;


function DeploymentSettings() {
    const {
        settings,
        updateSetting,
        saveSettings,
        settingsSaved,
        setSettingsSaved,
        isRunning,
        isPro,
        isStudio
    } = useContext(SettingsContext);
    const [deliveryMethod, setDeliveryMethod] = useState('zip');
    const [clearDirectory, setClearDirectory] = useState(false);
    const [githubAccountType, setGithubAccountType] = useState('personal');
    const [githubVisibility, setGithubVisibility] = useState('private');
    const [emptyBucketBeforeExport, setEmptyBucketBeforeExport] = useState(false);
    const [awsDisableAcl, setAwsDisableAcl] = useState(false);
    const [throttleGitHubRequests, setThrottleGitHubRequests] = useState(false);
    const [region, setRegion] = useState('us-east-2');
    const [awsAuthMethod, setAwsAuthMethod] = useState('aws-iam-key');
    const [hasCopied, setHasCopied] = useState(false);
    const [pages, setPages] = useState(false);
    const [testDisabled, setTestDisabled] = useState(false);
    const [testRunning, setTestRunning] = useState(false);
    const [deploymentOptions] = useState([
        {label: __('ZIP Archive', 'wp-static-export'), value: 'zip'},
        {label: __('Local Directory', 'wp-static-export'), value: 'local'}
    ]);
    const configuredElsewhere = deliveryMethod && !['zip', 'local'].includes(deliveryMethod);
    const selectableOptions = configuredElsewhere
        ? [...deploymentOptions, {label: __('Set in Deploy & Features', 'wp-static-export') + ' (' + deliveryMethod + ')', value: deliveryMethod}]
        : deploymentOptions;
    const canEditDeploymentSettings = !isStudio() || !!options.allow_studio_deployment_settings;

    const setSavingSettings = () => {
        saveSettings();
        setSettingsSaved(true);
        setTestDisabled(false);

        setTimeout(function () {
            setSettingsSaved(false);
        }, 2000);
    }

    useEffect(() => {
        if (settings.delivery_method) {
            setDeliveryMethod(settings.delivery_method);
        }

        if (settings.clear_directory_before_export) {
            setClearDirectory(settings.clear_directory_before_export);
        }

        if (settings.github_account_type) {
            setGithubAccountType(settings.github_account_type);
        }

        if (settings.github_repository_visibility) {
            setGithubVisibility(settings.github_repository_visibility);
        }

        if (settings.github_repository_visibility) {
            setGithubVisibility(settings.github_repository_visibility);
        }

        if (settings.github_throttle_requests) {
            setThrottleGitHubRequests(settings.github_throttle_requests);
        }

        if (settings.aws_empty) {
            setEmptyBucketBeforeExport(settings.aws_empty);
        }

        if (settings.aws_disable_acl) {
            setAwsDisableAcl(settings.aws_disable_acl);
        }

        if (settings.aws_auth_method) {
            setAwsAuthMethod(settings.aws_auth_method);
        }

        if (settings.aws_region) {
            setRegion(settings.aws_region);
        }

        // Get global page selection
        apiFetch({path: '/wp-static-export/v1/pages'}).then((fetched_pages) => {
            let pages = fetched_pages;

            pages.unshift({label: __('No page selected', 'wp-static-export'), value: 0});
            setPages(pages);
        });

    }, [settings]);

    return (<div className={"inner-settings"}>
        <Notice status="info" isDismissible={false}>
            <p>{__('GitHub, Netlify, Cloudflare Pages, Amazon S3, BunnyCDN and SFTP are configured under Deploy & Features.', 'wp-static-export')}{' '}
                <a href="admin.php?page=wp-static-export-features">{__('Open Deploy & Features', 'wp-static-export')}</a></p>
        </Notice>
        <Spacer margin={5}/>
        <Card>
            <CardHeader>
                <b>{__('Deployment Settings', 'wp-static-export')}</b>
            </CardHeader>
            <CardBody>
                <p>{__('Choose from a variety of deployment methods. Depending on your selection we either provide a ZIP file, export to a local directory or send your files to a remote destination.', 'wp-static-export')}</p>
                <SelectControl
                    label={__('Deployment method', 'wp-static-export')}
                    value={deliveryMethod}
                    options={selectableOptions}
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                    disabled={!canEditDeploymentSettings}
                    onChange={(method) => {
                        setDeliveryMethod(method);
                        updateSetting('delivery_method', method);
                        setTestDisabled(true);
                    }}
                />
            </CardBody>
        </Card>
        <Spacer margin={5}/>
        {canEditDeploymentSettings && <>
        {deliveryMethod === 'zip' &&
            <Card>
                <CardHeader>
                    <b>{__('ZIP', 'wp-static-export')}</b>
                </CardHeader>
                <CardBody>
                    <p>
                        {__('Get a download link in the activity log once the static export has finished.', 'wp-static-export')}
                    </p>
                </CardBody>
            </Card>
        }
        <Spacer margin={5}/>
        {deliveryMethod === 'local' &&
            <Card>
                <CardHeader>
                    <b>{__('Local Directory', 'wp-static-export')}</b>
                </CardHeader>
                <CardBody>
                    <TextControl
                        label={__('Path', 'wp-static-export')}
                        type={"text"}
                        help={__("This is the directory where your static files will be saved. We will create it automatically on the first export if it doesn't exist.", 'wp-static-export')}
                        placeholder={options.home_path + "public_static/"}
                        __next40pxDefaultSize
                        __nextHasNoMarginBottom
                        value={settings.local_dir}
                        onChange={(path) => {
                            updateSetting('local_dir', path);
                        }}
                    />
                    <Button
                        variant="secondary"
                        onClick={() => {
                            try {
                                navigator.clipboard.writeText(options.home_path);
                                setHasCopied(true);
                                setTimeout(() => setHasCopied(false), 1500);
                            } catch (e) {
                                console.error('Clipboard copy failed', e);
                            }
                        }}
                    >
                        {hasCopied ? __('Copied home path', 'wp-static-export') : __('Copy home path', 'wp-static-export')}
                    </Button>
                    <ToggleControl
                            __nextHasNoMarginBottom
                            label={__('Clear Local Directory', 'wp-static-export')}
                            help={
                                clearDirectory
                                    ? __('Clear local directory before running an export.', 'wp-static-export')
                                    : __('Don\'t clear local directory before running an export.', 'wp-static-export')
                            }
                            checked={clearDirectory}
                            onChange={(value) => {
                                setClearDirectory(value);
                                updateSetting('clear_directory_before_export', value);
                            }}
                        />
                </CardBody>
            </Card>
        }
        <>
            <Spacer margin={5}/>
            <Spacer margin={5}/>
            <Spacer margin={5}/>
            <Spacer margin={5}/>
            <Spacer margin={5}/>
        </>
        </>}
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
            {canEditDeploymentSettings && <>
                {'free' !== options.plan || isStudio() ?
                    <Button onClick={setSavingSettings} variant="primary">{__('Save Settings', 'wp-static-export')}</Button>
                    :
                    <>
                        {deliveryMethod === 'zip' &&
                            <Button onClick={setSavingSettings}
                                    variant="primary">{__('Save Settings', 'wp-static-export')}</Button>
                        }
                        {deliveryMethod === 'local' &&
                            <Button onClick={setSavingSettings}
                                    variant="primary">{__('Save Settings', 'wp-static-export')}</Button>
                        }
                    </>
                }
            </>
            }
            {canEditDeploymentSettings && 'pro' === options.plan && isPro() &&
                <Button
                    disabled={isRunning || testDisabled || testRunning}
                    variant={'secondary'}

                    isBusy={isRunning || testRunning}
                    onClick={() => {
                        setTestRunning(true);
                        apiFetch({
                            path: '/wp-static-export/v1/apply-single',
                            method: 'POST',
                        }).then(resp => {
                            if (parseInt(resp.status) === 404) {
                                alert(resp.message);
                            } else {
                                window.location.reload();
                            }

                        });
                    }}>
                    {testDisabled && __('Save settings to test', 'wp-static-export')}
                    {!testDisabled && __('Test Deployment', 'wp-static-export')}
                </Button>
            }
        </div>
    </div>)
}

export default DeploymentSettings;
