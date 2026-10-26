import {Button, Dashicon} from "@wordpress/components";
const {__} = wp.i18n;
function GenerateButtons(props) {
    const { children, canGenerate, isPaused, isDelayed, startExport, cancelExport, pauseExport, resumeExport, buttonLabel } = props;
    const hasPauseFeature = typeof pauseExport === 'function';
    const hasCancelFeature = typeof cancelExport === 'function';
    const hasResumeFeature = typeof resumeExport === 'function';
    const hasExportFeature = typeof startExport === 'function';

    return <div className="generate-buttons-container">
        {canGenerate && hasExportFeature && <Button
            onClick={() => {
                startExport();
            }}
            disabled={!canGenerate || isDelayed}
            className={'generate'}
        >
            {canGenerate && <>
                <Dashicon icon="update"/>
                {buttonLabel || __('Push', 'wp-static-export')}
            </>}

            {canGenerate && isDelayed>0 && <> {isDelayed}s</>}

            {!canGenerate && <Dashicon icon="update spin"/>}
        </Button>}
        {!canGenerate && <>
            {!isPaused && hasPauseFeature && <Button
                label={__('Pause', 'wp-static-export')}
                className={"ss-generate-media-button"}
                aria-label={__('Pause', 'wp-static-export')}
                onClick={() => pauseExport()}>
                <Dashicon icon={"controls-pause"}/>
            </Button>
            }
            {isPaused && hasResumeFeature && <Button
                label={__('Resume', 'wp-static-export')}
                className={"ss-generate-media-button"}
                aria-label={__('Resume', 'wp-static-export')}
                onClick={() => resumeExport()}>
                <Dashicon icon={"controls-play"}/>
            </Button>
            }
            { hasCancelFeature && <Button
                onClick={() => cancelExport()}
                label={__('Cancel', 'wp-static-export')}
                className={"ss-generate-cancel-button"}
                aria-label={__('Cancel', 'wp-static-export')}
            >
                <Dashicon icon={'no'}/>
            </Button> }
        </>}
        {children}
    </div>
}

export default GenerateButtons;
