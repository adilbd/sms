<?php

/**
 * Ok, glad you are here
 * first we get a config instance, and set the settings
 * $config = HTMLPurifier_Config::createDefault();
 * $config->set('Core.Encoding', $this->config->get('purifier.encoding'));
 * $config->set('Cache.SerializerPath', $this->config->get('purifier.cachePath'));
 * if ( ! $this->config->get('purifier.finalize')) {
 *     $config->autoFinalize = false;
 * }
 * $config->loadArray($this->getConfig());
 *
 * You must NOT delete the default settings
 * anything in settings should be compacted with params that needed to instance HTMLPurifier_Config.
 *
 * @link http://htmlpurifier.org/live/configdoc/plain.html
 */

// The only profile this app uses (see App\Support\PostBody). No h1 (PublicSeoTest
// requires exactly one <h1> per page, owned by the page template). Kept as `default` too
// so any call to Purifier::clean() without an explicit profile behaves the same way.
$postBody = [
    'HTML.Doctype' => 'HTML 4.01 Transitional',
    'HTML.Allowed' => 'p,br,strong,em,u,s,h2,h3,h4,ul,ol,li,blockquote,a[href|title|target|rel],img[src|alt|width|height],figure,figcaption,iframe[src|width|height|allowfullscreen|frameborder],div,hr,code,pre',
    'HTML.TargetNoreferrer' => true,
    // The Tiptap link toolbar sets target="_blank" (see resources/js/admin/components/RichTextEditor.vue).
    // HTMLPurifier strips the target attribute to anything not explicitly allowed here.
    'Attr.AllowedFrameTargets' => ['_blank'],
    'HTML.SafeIframe' => true,
    'URI.SafeIframeRegexp' => '%^https://(www\.youtube-nocookie\.com/embed/|player\.vimeo\.com/video/)%',
    'AutoFormat.AutoParagraph' => false,
    'AutoFormat.RemoveEmpty' => true,
];

return [
    'encoding' => 'UTF-8',
    'finalize' => true,
    'ignoreNonStrings' => false,
    'cachePath' => storage_path('app/purifier'),
    'cacheFileMode' => 0755,
    'settings' => [
        'default' => $postBody,
        'post_body' => $postBody,
        // figure/figcaption and iframe[allowfullscreen] aren't part of HTMLPurifier's
        // HTML 4.01 Transitional definition, so post_body needs them added explicitly.
        'custom_definition' => [
            'id' => 'html5-definitions',
            // Bumped when this file's definition-affecting settings change (HTML.Allowed,
            // custom elements/attributes), so HTMLPurifier's on-disk cache doesn't keep
            // serving a stale definition built under the old settings.
            'rev' => 2,
            'debug' => false,
            'elements' => [
                ['figure', 'Block', 'Optional: (figcaption, Flow) | (Flow, figcaption) | Flow', 'Common'],
                ['figcaption', 'Inline', 'Flow', 'Common'],
            ],
            'attributes' => [
                ['iframe', 'allowfullscreen', 'Bool'],
            ],
        ],
    ],

];
