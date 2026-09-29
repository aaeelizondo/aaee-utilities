import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';

// --- 1. Extend Block Attributes ---

const addVisibilityAttributes = ( settings ) => {
    // Skip the reusable block placeholder.
    if ( settings.name === 'core/block' ) {
        return settings;
    }

    // Add new attributes to all blocks to store the visibility state.
    // MUST match the keys registered/checked in visibility-toggle.php
    settings.attributes = {
        ...settings.attributes,
        // Legacy on/off toggle. Still read so previously hidden blocks stay hidden.
        aaeeLiveHide: {
            type: 'boolean',
            default: false,
        },
        // '' (visible), 'mobile', 'desktop' or 'all'
        aaeeHideOn: {
            type: 'string',
            default: '',
        },
    };

    return settings;
};

// Filter used to modify the settings of ALL registered blocks.
addFilter(
    'blocks.registerBlockType',
    'aaee-utilities/add-live-hide-attribute',
    addVisibilityAttributes
);


// --- 2. Inject the Control UI ---

const HIDE_OPTIONS = [
    { label: __( 'Visible on all devices', 'aaee-utilities' ), value: '' },
    { label: __( 'Hide on Mobile', 'aaee-utilities' ), value: 'mobile' },
    { label: __( 'Hide on Desktop', 'aaee-utilities' ), value: 'desktop' },
    { label: __( 'Hide on All Devices', 'aaee-utilities' ), value: 'all' },
];

const HELP_TEXT = {
    '': __( 'This block is VISIBLE on the front-end.', 'aaee-utilities' ),
    mobile: __( 'This block will be HIDDEN on mobile screens.', 'aaee-utilities' ),
    desktop: __( 'This block will be HIDDEN on desktop screens.', 'aaee-utilities' ),
    all: __( 'This block will be HIDDEN on the front-end for everyone.', 'aaee-utilities' ),
};

const withLiveHideControl = createHigherOrderComponent( ( BlockEdit ) => {
    return ( props ) => {
        const { attributes, setAttributes, isSelected } = props;
        const { aaeeLiveHide, aaeeHideOn } = attributes;

        // Blocks saved with the old toggle are treated as "Hide on All Devices".
        const hideOn = aaeeHideOn || ( aaeeLiveHide ? 'all' : '' );

        return (
            <>
                {/* Render the original block's editor component */}
                <BlockEdit { ...props } />

                {/* Only display the controls when the block is selected */}
                { isSelected && (
                    <InspectorControls>
                        <PanelBody title={ __( 'Block Visibility', 'aaee-utilities' ) } initialOpen={ false }>
                            <SelectControl
                                __nextHasNoMarginBottom
                                __next40pxDefaultSize
                                label={ __( 'Visibility on Live Site', 'aaee-utilities' ) }
                                value={ hideOn }
                                options={ HIDE_OPTIONS }
                                help={ HELP_TEXT[ hideOn ] }
                                // Clearing the legacy toggle migrates the block to the new attribute.
                                onChange={ ( newValue ) => setAttributes( { aaeeHideOn: newValue, aaeeLiveHide: undefined } ) }
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
