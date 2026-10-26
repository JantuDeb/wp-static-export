import {__experimentalSpacer as Spacer, Button, Card, Dashicon} from "@wordpress/components";
import {useContext} from "@wordpress/element";
import {SettingsContext} from "../context/SettingsContext";
import VersionInfo from "./VersionInfo";

const {__} = wp.i18n;
function SidebarMultisite( props = null ) {
    const { isPro } = useContext(SettingsContext);

    return (<Card className={"plugin-nav"}>
        <div className={"plugin-logo"}>
            <img alt={__('WP Static Export logo', 'wp-static-export')}
                 src={options.logo}/>
        </div>
        <Spacer margin={5}/>
        <Spacer margin={5}/>
        <Button href="https://github.com/JantuDeb/wp-static-export/releases" target="_blank">
            <Dashicon icon="editor-ul"/> {__('Changelog', 'wp-static-export')}
        </Button>
        <Button href="https://github.com/JantuDeb/wp-static-export#readme" target="_blank">
            <Dashicon icon="admin-links"/> {__('Documentation', 'wp-static-export')}
        </Button>
        <VersionInfo/>
    </Card>);
}

export default SidebarMultisite;
