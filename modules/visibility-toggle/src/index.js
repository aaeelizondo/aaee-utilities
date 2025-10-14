import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';

// --- 1. Extend Block Attributes ---

const addLiveHideAttribute = ( settings ) => {
    // Check if the block is a dynamic block type (e.g., core/html, core/shortcode)
    // or if it's the reusable block placeholder, and skip if necessary.
    if ( settings.name === 'core/block' ) {
        return settings;
    }

    // Add a new attribute to all blocks to store the toggle state.
    settings.attributes = {
        ...settings.attributes,
        // MUST match the key checked in the PHP file (aaee_live_hide_block_render)
        aaeeLiveHide: {
            type: 'boolean',
            default: false,
        },
    };

    return settings;
};

// Filter used to modify the settings of ALL registered blocks.
addFilter(
    'blocks.registerBlockType',
    'aaee-utilities/add-live-hide-attribute',
    addLiveHideAttribute
);


// --- 2. Inject the Control UI ---

const withLiveHideControl = createHigherOrderComponent( ( BlockEdit ) => {
    return ( props ) => {
        const { attributes, setAttributes, isSelected } = props;
        const { aaeeLiveHide } = attributes;

        return (
            <>
                {/* Render the original block's editor component */}
                <BlockEdit { ...props } />

                {/* Only display the controls when the block is selected */}
                { isSelected && (
                    <InspectorControls>
                        <PanelBody title={ __( 'Block Visibility', 'aaee-utilities' ) } initialOpen={ false }>
                            <ToggleControl
                                label={ __( 'Hide on Live Site', 'aaee-utilities' ) }
                                checked={ aaeeLiveHide }
                                help={ aaeeLiveHide ? 'This block will be HIDDEN on the front-end.' : 'This block will be VISIBLE on the front-end.' }
                                onChange={ ( newValue ) => setAttributes( { aaeeLiveHide: newValue } ) }
                            />
                        </PanelBody>
                    </InspectorControls>
                ) }
            </>
        );
    };
}, 'withLiveHideControl' );

// Filter used to wrap the editor component for ALL blocks.
addFilter(
    'editor.BlockEdit',
    'aaee-utilities/with-live-hide-control',
    withLiveHideControl
);