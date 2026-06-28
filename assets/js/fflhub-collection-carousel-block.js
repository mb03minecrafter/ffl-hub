(function(wp) {
    if (!wp || !wp.blocks || !wp.element || !wp.components || !wp.blockEditor || !wp.serverSideRender) {
        return;
    }

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var RangeControl = wp.components.RangeControl;
    var SelectControl = wp.components.SelectControl;
    var TextControl = wp.components.TextControl;
    var ToggleControl = wp.components.ToggleControl;
    var ServerSideRender = wp.serverSideRender.default || wp.serverSideRender;

    wp.blocks.registerBlockType('fflhub/collection-carousel', {
        title: 'FFLHub Collection Carousel',
        icon: 'slides',
        category: 'widgets',
        supports: {
            align: ['wide', 'full'],
            html: false
        },
        attributes: {
            title: { type: 'string', default: 'Shop Popular Collections' },
            taxonomy: { type: 'string', default: 'product_tag' },
            include: { type: 'string', default: '' },
            perPage: { type: 'number', default: 12 },
            orderBy: { type: 'string', default: 'count' },
            order: { type: 'string', default: 'desc' },
            hideEmpty: { type: 'boolean', default: true },
            showDescription: { type: 'boolean', default: true },
            showCount: { type: 'boolean', default: true },
            autoplay: { type: 'boolean', default: true },
            interval: { type: 'number', default: 4500 }
        },
        edit: function(props) {
            var attrs = props.attributes;
            var setAttributes = props.setAttributes;

            return el(
                Fragment,
                {},
                el(
                    InspectorControls,
                    {},
                    el(
                        PanelBody,
                        { title: 'Collection carousel', initialOpen: true },
                        el(TextControl, {
                            label: 'Heading',
                            value: attrs.title || '',
                            onChange: function(value) {
                                setAttributes({ title: value });
                            }
                        }),
                        el(TextControl, {
                            label: 'Term IDs or slugs',
                            help: 'Optional comma-separated list. Leave blank to use collection tags with SEO copy, focus keywords, or archive images.',
                            value: attrs.include || '',
                            onChange: function(value) {
                                setAttributes({ include: value });
                            }
                        }),
                        el(RangeControl, {
                            label: 'Collections shown',
                            min: 1,
                            max: 100,
                            value: attrs.perPage || 12,
                            onChange: function(value) {
                                setAttributes({ perPage: value || 12 });
                            }
                        }),
                        el(SelectControl, {
                            label: 'Order by',
                            value: attrs.orderBy || 'count',
                            options: [
                                { label: 'Product count', value: 'count' },
                                { label: 'Name', value: 'name' },
                                { label: 'Slug', value: 'slug' },
                                { label: 'Term ID', value: 'term_id' }
                            ],
                            onChange: function(value) {
                                setAttributes({ orderBy: value });
                            }
                        }),
                        el(SelectControl, {
                            label: 'Order',
                            value: attrs.order || 'desc',
                            options: [
                                { label: 'Descending', value: 'desc' },
                                { label: 'Ascending', value: 'asc' }
                            ],
                            onChange: function(value) {
                                setAttributes({ order: value });
                            }
                        }),
                        el(ToggleControl, {
                            label: 'Hide empty collections',
                            checked: attrs.hideEmpty !== false,
                            onChange: function(value) {
                                setAttributes({ hideEmpty: !!value });
                            }
                        }),
                        el(ToggleControl, {
                            label: 'Show SEO meta descriptions',
                            checked: attrs.showDescription !== false,
                            onChange: function(value) {
                                setAttributes({ showDescription: !!value });
                            }
                        }),
                        el(ToggleControl, {
                            label: 'Show product counts',
                            checked: attrs.showCount !== false,
                            onChange: function(value) {
                                setAttributes({ showCount: !!value });
                            }
                        }),
                        el(ToggleControl, {
                            label: 'Auto advance',
                            checked: attrs.autoplay !== false,
                            onChange: function(value) {
                                setAttributes({ autoplay: !!value });
                            }
                        }),
                        el(RangeControl, {
                            label: 'Auto advance speed',
                            min: 1500,
                            max: 10000,
                            step: 250,
                            value: attrs.interval || 4500,
                            onChange: function(value) {
                                setAttributes({ interval: value || 4500 });
                            }
                        })
                    )
                ),
                el(ServerSideRender, {
                    block: 'fflhub/collection-carousel',
                    attributes: attrs
                })
            );
        },
        save: function() {
            return null;
        }
    });
})(window.wp);
