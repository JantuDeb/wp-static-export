import {Button, Flex, FlexBlock, FlexItem, TextControl} from "@wordpress/components";
import {useState} from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";

const {__} = wp.i18n;

export default function EnvironmentForm({onClose, setSelectableEnvironments, setSelectedEnvironment}) {
    const [name, setName] = useState('');
    const [creating, setCreating] = useState(false);

    const createNew = () => {
        setCreating(true);
        apiFetch({
            path: '/wp-static-export/v1/environment',
            method: 'POST',
            data: {title: name},
        }).then(resp => {
            let environments = Object.keys(resp.environments).map(function (version) {
                return {label: resp.environments[version], value: version}
            });

            setSelectableEnvironments(environments);
            setSelectedEnvironment(resp.current_environment);

            onClose();
        }).catch(resp => {
            alert(resp.message);
        }).finally(() => setCreating(false));
    }

    return (
        <div className={'ss-environment-form'}>
            <TextControl
                label={"Name"}
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                onChange={(val) => setName(val)}
                value={name}
            />
            <p>{__('A new environment will be created with the current configuration.', 'wp-static-export')}</p>
            <Flex>
                <FlexBlock>
                    <Button variant={'primary'} onClick={createNew} isBusy={creating}>
                        {creating ? __('Creating...', 'wp-static-export') : __('Create', 'wp-static-export')}
                    </Button>
                </FlexBlock>
                <FlexBlock>
                    <Button variant={'secondary'} onClick={onClose}>{__('Cancel', 'wp-static-export')}</Button>
                </FlexBlock>
            </Flex>

        </div>
    )
}