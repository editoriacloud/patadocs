<?php
/**
 * PATADOCS — advertising (Google AdSense) and Google / search-engine integrations, managed in Admin → Ads & Google.
 *
 * Policy-first by design (AdSense Program Policies + "Ad placement policies"):
 *  - ads only on pages with real publisher content: never on payment / checkout / download / recovery / error /
 *    admin / login pages, empty search results, or pages that are not indexed (noindex);
 *  - ad spaces sit BETWEEN content blocks — never inside the navigation, in pop-ups, or next to Buy / Download /
 *    Pay buttons (accidental clicks), never styled to look like content, always labelled "Advertisement";
 *  - every space reserves its height before the ad loads (no layout shift) and loads when it scrolls near the
 *    screen (no slow pages); a page never carries more than the configured number of ad units.
 */

/** The ad spaces the templates offer: key => [title, where it appears, default min-height px]. */
function ad_slots(): array
{
    return [
        'home_mid'        => ['Home — between sections', 'Home page, after the document lists (before collections).', 280],
        'home_bottom'     => ['Home — before the call-to-action', 'Home page, near the end.', 280],
        'doc_content'     => ['Document — after the description', 'Document page, below “About this document” (far from the Buy button).', 280],
        'doc_related'     => ['Document — before related documents', 'Document page, between reviews and related documents.', 280],
        'category_list'   => ['Category — after the document list', 'Category pages, below the list and pager.', 250],
        'search_results'  => ['Search — after the results', 'Search / browse results, below the list (only when there are results).', 250],
        'collection_list' => ['Collection — after the document list', 'Bundle pages, below the included documents.', 250],
        'list_pages'      => ['Popular / Categories — after the list', 'Popular and All categories pages.', 250],
        'footer'          => ['Above the footer', 'Every eligible page, above the footer links.', 120],
    ];
}

function ads_publisher(): string
{
    $p = strtolower(trim((string)setting('ads_publisher_id')));
    $p = preg_replace('/^ca-/', '', $p);
    return preg_match('/^pub-\d{10,20}$/', $p) ? $p : '';
}

/** Should this page carry ads at all? (content pages only — see file comment) */
function ads_page_eligible(array $meta): bool
{
    if (setting('ads_enabled', '0') !== '1' || ads_publisher() === '') { return false; }
    if (!empty($meta['no_ads'])) { return false; }
    if (strpos(seo_robots($meta), 'noindex') !== false) { return false; }             // not indexed → not an ad page
    if (setting('ads_hide_admins', '1') === '1' && function_exists('admin_current') && admin_current()) { return false; }   // no self-impressions
    return true;
}

/** <head> additions: site verifications, AdSense account meta + library, GA4 with Consent Mode v2, Tag Manager. */
function google_head_html(array $meta): string
{
    $h = '';
    foreach (['google_site_verification' => 'google-site-verification', 'bing_site_verification' => 'msvalidate.01', 'yandex_site_verification' => 'yandex-verification', 'pinterest_site_verification' => 'p:domain_verify'] as $k => $name) {
        foreach (preg_split('/[\s,]+/', trim((string)setting($k))) as $v) {                // several codes allowed (old + new property)
            if ($v !== '' && preg_match('/^[A-Za-z0-9_\-=.]{6,120}$/', $v)) { $h .= '<meta name="' . $name . '" content="' . e($v) . "\">\n"; }
        }
    }
    $pub = ads_publisher();
    if ($pub !== '') { $h .= '<meta name="google-adsense-account" content="ca-' . e($pub) . "\">\n"; }   // AdSense site verification
    $ga = strtoupper(trim((string)setting('ga4_id'))); $gtm = strtoupper(trim((string)setting('gtm_id')));
    $ga = preg_match('/^G-[A-Z0-9]{4,15}$/', $ga) ? $ga : ''; $gtm = preg_match('/^GTM-[A-Z0-9]{4,12}$/', $gtm) ? $gtm : '';
    $adsHere = ads_page_eligible($meta);
    if ($ga !== '' || $gtm !== '' || $adsHere) {
        // Consent Mode v2: in the EEA, the UK and Switzerland everything starts "denied" until the visitor answers the
        // Google-certified consent message (AdSense → Privacy & messaging). Elsewhere (e.g. Kenya) it starts granted.
        $eea = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IS','IE','IT','LV','LI','LT','LU','MT','NL','NO','PL','PT','RO','SK','SI','ES','SE','GB','CH'];
        $h .= "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
            . "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',region:" . json_encode($eea) . ",wait_for_update:500});"
            . "gtag('consent','default',{ad_storage:'granted',ad_user_data:'granted',ad_personalization:'granted',analytics_storage:'granted'});</script>\n";
    }
    if ($gtm !== '') {
        $h .= "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer'," . json_encode($gtm) . ");</script>\n";
    }
    if ($ga !== '') {
        $h .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . e($ga) . "\"></script>\n"
            . "<script>gtag('js',new Date());gtag('config'," . json_encode($ga) . ",{anonymize_ip:true});</script>\n";
    }
    if ($adsHere) {
        $h .= '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-' . e($pub) . '" crossorigin="anonymous"></script>' . "\n";
        $h .= '<link rel="preconnect" href="https://googleads.g.doubleclick.net" crossorigin><link rel="preconnect" href="https://tpc.googlesyndication.com" crossorigin>' . "\n";
    }
    return $h;
}

/** Right after <body>: Tag Manager's no-JavaScript fallback. */
function google_body_html(): string
{
    $gtm = strtoupper(trim((string)setting('gtm_id')));
    if (!preg_match('/^GTM-[A-Z0-9]{4,12}$/', $gtm)) { return ''; }
    return '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . e($gtm) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
}

/**
 * One ad space. $meta is the page's $meta (eligibility). Nothing is printed when ads are off, the page is not
 * eligible, the space is disabled or the per-page limit is reached. Admins see an outline instead (preview mode).
 */
function ad_slot(string $key, array $meta): string
{
    static $shown = 0;
    $slots = ad_slots();
    if (!isset($slots[$key]) || setting('ad_' . $key . '_on', '0') !== '1') { return ''; }
    $preview = setting('ads_preview', '0') === '1' && function_exists('admin_current') && admin_current();
    if (!$preview && !ads_page_eligible($meta)) { return ''; }
    if ($shown >= max(1, min(10, (int)setting('ads_max_per_page', '3')))) { return ''; }
    $shown++;
    $h = max(50, min(600, (int)setting('ad_' . $key . '_height', (string)$slots[$key][2])));
    $dev = setting('ad_' . $key . '_devices', 'all');
    $cls = 'pd-ad' . ($dev === 'desktop' ? ' pd-ad-desktop' : ($dev === 'mobile' ? ' pd-ad-mobile' : ''));
    $label = trim((string)setting('ads_label', 'Advertisement'));
    $out = '<aside class="' . $cls . '" aria-label="' . e($label !== '' ? $label : 'Advertisement') . '" data-slot="' . e($key) . '">'
        . ($label !== '' ? '<div class="pd-ad-label">' . e($label) . '</div>' : '')
        . '<div class="pd-ad-box" style="min-height:' . $h . 'px">';
    if ($preview && !ads_page_eligible($meta)) {
        return $out . '<div class="pd-ad-preview">Ad space “' . e($slots[$key][0]) . '” — no ad here (' . e(ads_publisher() === '' ? 'no publisher ID' : (setting('ads_enabled', '0') !== '1' ? 'ads switched off' : 'this page is not an ad page')) . ')</div></div></aside>';
    }
    $custom = trim((string)setting('ad_' . $key . '_code'));
    $slotId = preg_replace('/\D/', '', (string)setting('ad_' . $key . '_slot'));
    if ($custom !== '') {                                                   // pasted code from AdSense (e.g. in-article unit)
        $out .= $custom;
    } elseif ($slotId !== '') {
        $fmt = setting('ad_' . $key . '_format', 'auto');
        $attrs = 'class="adsbygoogle" style="display:block' . ($fmt === 'fluid' ? '; text-align:center' : '') . '" data-ad-client="ca-' . e(ads_publisher()) . '" data-ad-slot="' . e($slotId) . '"';
        if ($fmt === 'fluid') { $attrs .= ' data-ad-format="fluid" data-ad-layout="in-article"'; }
        else { $attrs .= ' data-ad-format="' . e(in_array($fmt, ['auto', 'horizontal', 'rectangle', 'vertical'], true) ? $fmt : 'auto') . '" data-full-width-responsive="true"'; }
        if (setting('ads_test_mode', '0') === '1') { $attrs .= ' data-adtest="on"'; }
        $out .= '<ins ' . $attrs . '></ins>';
    } elseif ($preview) {
        $out .= '<div class="pd-ad-preview">Ad space “' . e($slots[$key][0]) . '” — add an AdSense ad unit slot ID in Admin → Ads &amp; Google</div>';
    } else {
        $shown--;                                                           // nothing to show: don't use up the page budget
        return '';
    }
    return $out . '</div></aside>';
}

/** ads.txt lines (IAB standard). Google's certification authority id is fixed. */
function ads_txt(): string
{
    $lines = [];
    if (ads_publisher() !== '') { $lines[] = 'google.com, ' . ads_publisher() . ', DIRECT, f08c47fec0942fa0'; }
    foreach (preg_split('/\r\n|\r|\n/', (string)setting('ads_txt_extra')) as $l) {
        $l = trim($l);
        if ($l !== '' && preg_match('/^[A-Za-z0-9.\-]+\s*,\s*[A-Za-z0-9\-_.]+\s*,\s*(DIRECT|RESELLER)(\s*,\s*[A-Za-z0-9]+)?$/i', $l)) { $lines[] = $l; }
    }
    return $lines ? implode("\n", array_unique($lines)) . "\n" : '';
}
