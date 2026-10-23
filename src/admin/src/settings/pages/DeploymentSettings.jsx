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
        {label: __('ZIP Archive', 'simply-static'), value: 'zip'},
        {label: __('Local Directory', 'simply-static'), value: 'local'}
    ]);
    const configuredElsewhere = deliveryMethod && !['zip', 'local'].includes(deliveryMethod);
    const selectableOptions = configuredElsewhere
        ? [...deploymentOptions, {label: __('Set in Deploy & Features', 'simply-static') + ' (' + deliveryMethod + ')', value: deliveryMethod}]
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
        apiFetch({path: '/simplystatic/v1/pages'}).then((fetched_pages) => {
            let pages = fetched_pages;

            pages.unshift({label: __('No page selected', 'simply-static'), value: 0});
            setPages(pages);
        });

    }, [settings]);

    return (<div className={"inner-settings"}>
        <Notice status="info" isDismissible={false}>
            <p>{__('GitHub, Netlify, Cloudflare Pages, Amazon S3, BunnyCDN and SFTP are configured under Deploy & Features.', 'simply-static')}{' '}
                <a href="admin.php?page=simply-static-features">{__('Open Deploy & Features', 'simply-static')}</a></p>
        </Notice>
        <Spacer margin={5}/>
        <Card>
            <CardHeader>
                <b>{__('Deployment Settings', 'simply-static')}</b>
            </CardHeader>
            <CardBody>
                <p>{__('Choose from a variety of deployment methods. Depending on your selection we either provide a ZIP file, export to a local directory or send your files to a remote destination.', 'simply-static')}</p>
                <SelectControl
                    label={__('Deployment method', 'simply-static')}
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
                    <b>{__('ZIP', 'simply-static')}</b>
                </CardHeader>
                <CardBody>
                    <p>
                        {__('Get a download link in the activity log once the static export has finished.', 'simply-static')}
                    </p>
                </CardBody>
            </Card>
        }
        <Spacer margin={5}/>
        {deliveryMethod === 'local' &&
            <Card>
                <CardHeader>
                    <b>{__('Local Directory', 'simply-static')}</b>
                </CardHeader>
                <CardBody>
                    <TextControl
                        label={__('Path', 'simply-static')}
                        type={"text"}
                        help={__("This is the directory where your static files will be saved. We will create it automatically on the first export if it doesn't exist.", 'simply-static')}
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
                        {hasCopied ? __('Copied home path', 'simply-static') : __('Copy home path', 'simply-static')}
                    </Button>
                    <ToggleControl
                            __nextHasNoMarginBottom
                            label={__('Clear Local Directory', 'simply-static')}
                            help={
                                clearDirectory
                                    ? __('Clear local directory before running an export.', 'simply-static')
                                    : __('Don\'t clear local directory before running an export.', 'simply-static')
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
                                {__('Settings saved successfully.', 'simply-static')}
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
                    <Button onClick={setSavingSettings} variant="primary">{__('Save Settings', 'simply-static')}</Button>
                    :
                    <>
                        {deliveryMethod === 'zip' &&
                            <Button onClick={setSavingSettings}
                                    variant="primary">{__('Save Settings', 'simply-static')}</Button>
                        }
                        {deliveryMethod === 'local' &&
                            <Button onClick={setSavingSettings}
                                    variant="primary">{__('Save Settings', 'simply-static')}</Button>
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
                            path: '/simplystatic/v1/apply-single',
                            method: 'POST',
                        }).then(resp => {
                            if (parseInt(resp.status) === 404) {
                                alert(resp.message);
                            } else {
                                window.location.reload();
                            }

                        });
                    }}>
                    {testDisabled && __('Save settings to test', 'simply-static')}
                    {!testDisabled && __('Test Deployment', 'simply-static')}
                </Button>
            }
        </div>
    </div>)
}

export default DeploymentSettings;
