import {Animate, Button, Notice} from "@wordpress/components";
import apiFetch from "@wordpress/api-fetch";
import {useState} from "@wordpress/element";
const {__} = wp.i18n;

function LogButtons() {
    const [logDeleted, setLogDeleted] = useState(false);

    const deleteLog = () => {
        apiFetch({
            path: '/wp-static-export/v1/delete-log',
            method: 'POST',
        });

        setLogDeleted(true);

        setTimeout(function () {
            setLogDeleted(false);
        }, 2000);
    }

    return (
        <>
        <Button variant="primary" href={options.log_file} download={true}
                style={{marginRight: "10px"}}>{__('Download Log', 'wp-static-export')}</Button>
        <Button variant="secondary"
            onClick={deleteLog}>{__('Clear Log', 'wp-static-export')}</Button>
        {logDeleted &&
        <Animate type="slide-in" options={{origin: 'top'}}>
            {() => (
                <Notice status="success" isDismissible={false}>
                    <p>
                        {__('Log file cleared.', 'wp-static-export')}
                    </p>
                </Notice>
            )}
        </Animate>
        }
        </>
    )
}

export default LogButtons;