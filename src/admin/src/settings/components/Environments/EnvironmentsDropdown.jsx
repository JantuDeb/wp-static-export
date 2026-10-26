import {Button, Flex, FlexBlock, FlexItem, SelectControl} from "@wordpress/components";
const {__} = wp.i18n;

export default function EnvironmentDropdown({ onChange, current, environments, disabled, onDelete, isStudio }) {
    const helpText = isStudio
        ? __('Choose an environment.', 'wp-static-export')
        : __('Choose an environment or create a new one to configure settings.', 'wp-static-export');

    return (
        <Flex>
            <FlexItem style={{minWidth: "80%"}}>
                <SelectControl
                    disabled={disabled}
                    value={current}
                    options={environments}
                    help={helpText}
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                    onChange={onChange}
                />
            </FlexItem>
            <FlexItem>
                <Button
                    className={'environment-delete-button'}
                    variant={'tertiary'}
                    label={__('Delete selected environment', 'wp-static-export')}
                    size={'small'}
                    icon={'trash'}
                    disabled={disabled}
                    onClick={onDelete}
                ></Button>
            </FlexItem>
        </Flex>
    )
}
