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

    wp.blocks.registerBlockType('fflhub/blog-post-carousel', {
        title: 'FFLHub Blog Post Carousel',
        icon: 'welcome-write-blog',
        category: 'widgets',
        supports: {
            align: ['wide', 'full'],
            html: false
        },
        attributes: {
            title: { type: 'string', default: 'Latest From the Blog' },
            include: { type: 'string', default: '' },
            categorySlugs: { type: 'string', default: '' },
            perPage: { type: 'number', default: 8 },
            orderBy: { type: 'string', default: 'date' },
            order: { type: 'string', default: 'desc' },
            showDescription: { type: 'boolean', default: true },
            showMeta: { type: 'boolean', default: true },
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
                        { title: 'Blog post carousel', initialOpen: true },
                        el(TextControl, {
                            label: 'Heading',
                            value: attrs.title || '',
                            onChange: function(value) {
                                setAttributes({ title: value });
                            }
                        }),
                        el(TextControl, {
                            label: 'Post IDs or slugs',
                            help: 'Optional comma-separated list. Leave blank to use recent posts.',
                            value: attrs.include || '',
                            onChange: function(value) {
                                setAttributes({ include: value });
                            }
                        }),
                        el(TextControl, {
                            label: 'Category slugs',
                            help: 'Optional comma-separated category slugs. Ignored when specific posts are selected.',
                            value: attrs.categorySlugs || '',
                            onChange: function(value) {
                                setAttributes({ categorySlugs: value });
                            }
                        }),
                        el(RangeControl, {
                            label: 'Posts shown',
                            min: 1,
                            max: 100,
                            value: attrs.perPage || 8,
                            onChange: function(value) {
                                setAttributes({ perPage: value || 8 });
                            }
                        }),
                        el(SelectControl, {
                            label: 'Order by',
                            value: attrs.orderBy || 'date',
                            options: [
                                { label: 'Publish date', value: 'date' },
                                { label: 'Title', value: 'title' },
                                { label: 'Modified date', value: 'modified' },
                                { label: 'Menu order', value: 'menu_order' },
                                { label: 'Random', value: 'rand' }
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
                            label: 'Show SEO meta descriptions',
                            checked: attrs.showDescription !== false,
                            onChange: function(value) {
                                setAttributes({ showDescription: !!value });
                            }
                        }),
                        el(ToggleControl, {
                            label: 'Show category/date badge',
                            checked: attrs.showMeta !== false,
                            onChange: function(value) {
                                setAttributes({ showMeta: !!value });
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
                    block: 'fflhub/blog-post-carousel',
                    attributes: attrs
                })
            );
        },
        save: function() {
            return null;
        }
    });
})(window.wp);
