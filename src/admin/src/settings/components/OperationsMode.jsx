import {
    Button,
    Card,
    CardBody,
    CardHeader,
    Notice,
    TextControl,
} from '@wordpress/components';
import {useState} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const {__} = wp.i18n;

const getErrorMessage = (error) => {
    if (error && error.message) {
        return error.message;
    }

    return __('The operations mode request could not be completed.', 'wp-static-export');
};

function OperationsMode({locked = false}) {
    const mode = options.operations_mode || {};
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [busy, setBusy] = useState('');
    const [error, setError] = useState('');
    const minimumPassword = mode.minimum_password || 8;

    const request = (path, data = {}) => {
        setBusy(path);
        setError('');

        return apiFetch({
            path: `/simplystatic-pro/v1/operations-mode/${path}`,
            method: 'POST',
            data,
        }).then(() => {
            window.location.reload();
        }).catch((requestError) => {
            setError(getErrorMessage(requestError));
        }).finally(() => {
            setBusy('');
        });
    };

    const enableOrUpdate = () => {
        if ((!mode.enabled || password) && password.length < minimumPassword) {
            setError(
                __('The operations mode password must contain at least 8 characters.', 'wp-static-export')
            );
            return;
        }

        if (password !== confirmation) {
            setError(__('The password confirmation does not match.', 'wp-static-export'));
            return;
        }

        request('configure', {enabled: true, password});
    };

    if (locked || (mode.enabled && !mode.unlocked)) {
        return (
            <Card>
                <CardHeader>
                    <b>{__('Operations mode', 'wp-static-export')}</b>
                </CardHeader>
                <CardBody>
                    <p>{__('Publishing remains available. Enter the operations password to access deployment, account, and plugin settings.', 'wp-static-export')}</p>
                    {error && <Notice status="error" isDismissible={false}><p>{error}</p></Notice>}
                    <TextControl
                        label={__('Operations password', 'wp-static-export')}
                        type="password"
                        value={password}
                        autoComplete="current-password"
                        disabled={!!busy}
                        __next40pxDefaultSize
                        __nextHasNoMarginBottom
                        onChange={setPassword}
                        onKeyDown={(event) => {
                            if ('Enter' === event.key && password) {
                                request('unlock', {password});
                            }
                        }}
                    />
                    <p>
                        <Button
                            variant="primary"
                            disabled={!!busy || !password}
                            isBusy={'unlock' === busy}
                            onClick={() => request('unlock', {password})}
                        >
                            {__('Unlock settings', 'wp-static-export')}
                        </Button>
                    </p>
                </CardBody>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <b>{__('Operations mode', 'wp-static-export')}</b>
            </CardHeader>
            <CardBody>
                {mode.enabled ?
                    <Notice status="success" isDismissible={false}>
                        <p>{__('Operations mode is enabled and settings are currently unlocked for support.', 'wp-static-export')}</p>
                    </Notice>
                    :
                    <p>{__('Protect deployment credentials and configuration from client changes while keeping publishing and diagnostics available.', 'wp-static-export')}</p>
                }

                <TextControl
                    label={mode.enabled ? __('New password (optional)', 'wp-static-export') : __('Operations password', 'wp-static-export')}
                    help={mode.enabled
                        ? __('Leave blank to keep the existing password.', 'wp-static-export')
                        : __('Use at least 8 characters. The password is stored as a one-way hash.', 'wp-static-export')}
                    type="password"
                    value={password}
                    autoComplete="new-password"
                    disabled={!!busy}
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                    onChange={setPassword}
                />
                <TextControl
                    label={__('Confirm password', 'wp-static-export')}
                    type="password"
                    value={confirmation}
                    autoComplete="new-password"
                    disabled={!!busy}
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                    onChange={setConfirmation}
                />

                {error && <Notice status="error" isDismissible={false}><p>{error}</p></Notice>}

                <p>
                    <Button
                        variant="primary"
                        disabled={!!busy || (!mode.enabled && !password)}
                        isBusy={'configure' === busy}
                        onClick={enableOrUpdate}
                    >
                        {mode.enabled ? __('Update operations mode', 'wp-static-export') : __('Enable operations mode', 'wp-static-export')}
                    </Button>
                    {mode.enabled &&
                        <>
                            {' '}
                            <Button
                                variant="secondary"
                                disabled={!!busy}
                                isBusy={'lock' === busy}
                                onClick={() => request('lock')}
                            >
                                {__('Lock settings now', 'wp-static-export')}
                            </Button>
                            {' '}
                            <Button
                                variant="tertiary"
                                isDestructive
                                disabled={!!busy}
                                onClick={() => request('configure', {enabled: false})}
                            >
                                {__('Disable operations mode', 'wp-static-export')}
                            </Button>
                        </>
                    }
                </p>
                {mode.enabled &&
                    <p>{__('Unlocked sessions expire automatically after one hour.', 'wp-static-export')}</p>
                }
            </CardBody>
        </Card>
    );
}

export default OperationsMode;
