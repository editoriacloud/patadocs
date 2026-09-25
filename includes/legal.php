<?php
/**
 * PATADOCS — legal & trust pages (Privacy Policy, Terms, Cookies, Copyright/takedown, Disclaimer).
 * Text is editable in Admin → Legal pages; an empty page uses the default below. Placeholders:
 * {site} {url} {email} {phone} {date}. Simple markup: "## Heading", "- list item", blank line = new paragraph,
 * links written as full URLs become clickable.
 */

function legal_pages(): array
{
    return [
        'privacy-policy' => ['Privacy Policy', 'How we collect, use and protect personal data — including advertising cookies and your choices.'],
        'terms'          => ['Terms of Use', 'The rules for using the site, buying and downloading documents, and contributing resources.'],
        'cookie-policy'  => ['Cookie Policy', 'Which cookies and similar technologies the site uses, and how to control them.'],
        'copyright'      => ['Copyright & Takedown', 'How we respect copyright and how rights holders can have material removed.'],
        'disclaimer'     => ['Disclaimer', 'The limits of our responsibility for documents and third-party content.'],
    ];
}

function legal_default(string $slug): string
{
    switch ($slug) {
        case 'privacy-policy': return <<<'TXT'
This Privacy Policy explains how {site} ({url}) collects, uses and protects information when you browse the site, buy or download documents, or contact us. We follow the Kenya Data Protection Act, 2019 and, where it applies to you, the EU/UK General Data Protection Regulation.

## Information we collect
- Purchase details: the document you buy, the amount, the invoice reference and the M-Pesa confirmation (receipt code and the phone number used to pay). Payments are processed by our payment partner (Editoria Payment Hub and Safaricom M-Pesa); we never see or store your M-Pesa PIN.
- Information you give us: your name, email address or phone number when you contact us, request a document, contribute a resource or leave a review.
- Technical information: IP address (stored in a shortened, hashed form for security), browser type, pages visited and searches made, used to keep the site secure and to improve it.

## How we use information
- To deliver the documents you paid for and to let you recover a purchase later.
- To answer requests and messages.
- To prevent fraud and abuse, and to keep the service secure.
- To understand how the site is used (analytics) and to show advertising that keeps many documents free.

## Advertising and Google cookies
We use Google AdSense to show ads. Third-party vendors, including Google, use cookies to serve ads based on a user's prior visits to this website or other websites. Google's use of advertising cookies enables it and its partners to serve ads to our users based on their visits to this site and/or other sites on the Internet.
- You may opt out of personalised advertising by visiting Google Ads Settings: https://adssettings.google.com
- You can also opt out of some third-party vendors' use of cookies for personalised advertising at https://www.aboutads.info/choices
- Learn how Google uses information from sites that use its services: https://policies.google.com/technologies/partner-sites
Visitors in the European Economic Area, the United Kingdom and Switzerland are asked for consent before advertising or analytics cookies are used.

## Analytics
We may use Google Analytics to understand how visitors use the site. It collects information such as pages viewed and the approximate location, using cookies. See https://policies.google.com/privacy

## Sharing
We do not sell personal data. We share it only with service providers who help us run the site (hosting, payments, email, analytics, advertising), when the law requires it, or to protect our rights and users.

## Retention and security
We keep purchase records for as long as needed to provide downloads, resolve disputes and meet accounting duties. Data is protected with access controls, encrypted connections (HTTPS) and limited retention of logs.

## Your rights
You may ask to access, correct or delete your personal data, or object to its use, by writing to {email}. You may also complain to the Office of the Data Protection Commissioner (Kenya): https://www.odpc.go.ke

## Children
The site is intended for a general audience. We do not knowingly collect personal data from children under 13 without parental consent.

## Changes
We may update this policy; the date below shows the latest version.

Contact: {email} {phone}
Last updated: {date}
TXT;
        case 'terms': return <<<'TXT'
By using {site} ({url}) you agree to these Terms of Use.

## Using the site
- You may browse, preview and download documents for your personal, educational or internal business use.
- You may not copy, resell or redistribute paid documents, remove watermarks, or attempt to bypass payment or download limits.
- You must not upload or request material you do not have the right to share.

## Purchases and downloads
- Prices are shown in Kenya Shillings and paid through M-Pesa via our payment partner.
- A document is unlocked only after the payment is confirmed. Download links are personal, time-limited and limited in number.
- Keep your invoice reference or M-Pesa code: it lets you recover your purchase.
- If a paid document is broken or not as described, contact us within 7 days at {email} and we will fix it, replace it or refund you.

## Contributions and reviews
By contributing a resource you confirm you own it or have permission to share it, and you allow us to publish it on the site. Reviews must be honest and about the document; we may moderate or remove them.

## Availability and changes
We work to keep the site available but do not guarantee uninterrupted service. We may update these terms; continued use means you accept the latest version.

## Law
These terms are governed by the laws of Kenya.

Contact: {email}
Last updated: {date}
TXT;
        case 'cookie-policy': return <<<'TXT'
This page explains the cookies and similar technologies {site} uses.

## Essential cookies
- A session cookie keeps security features working (for example protection of forms and payments). It is deleted when you close the browser.
- Your theme choice (light/dark) and saved documents are stored in your own browser (local storage) and never sent to us.

## Analytics cookies
If enabled, Google Analytics uses cookies to count visits and see which pages are useful. See https://policies.google.com/privacy

## Advertising cookies
Google and its partners use cookies to show ads, including ads based on your visits to this and other websites. You can turn off personalised ads at https://adssettings.google.com or https://www.aboutads.info/choices
Visitors in the European Economic Area, the United Kingdom and Switzerland are asked for consent first, and can change their choice at any time.

## Controlling cookies
You can delete or block cookies in your browser settings. Blocking essential cookies may stop payments or downloads from working.

Contact: {email}
Last updated: {date}
TXT;
        case 'copyright': return <<<'TXT'
{site} respects intellectual property. We only publish documents we own, have permission to share, or that are freely shareable (for example public government documents), and we remove infringing material quickly.

## Reporting a copyright problem
If you believe a document on {site} infringes your copyright, send a notice to {email} with:
- the link to the document on our site;
- a description of your work and proof that you own it or act for the owner;
- your name, phone number and email address;
- a statement that the information is accurate and that you are the owner or authorised to act.
You can also use the "Report document" button on any document page.

## What we do
We review every notice, normally within 48 hours. Infringing documents are unpublished and repeat infringers lose the ability to contribute.

Contact: {email} {phone}
Last updated: {date}
TXT;
        case 'disclaimer': return <<<'TXT'
The documents on {site} are provided to help with education, business and everyday tasks. We check them, but we cannot guarantee that every document is complete, current or suitable for your specific purpose.
- Always confirm official requirements (for example current curriculum designs, government forms or legal templates) with the relevant authority.
- Documents are not professional legal, medical, financial or tax advice.
- Links to other websites and advertisements are provided by third parties; we are not responsible for their content.
- Contributed documents belong to their authors and are shared with their permission.

Contact: {email}
Last updated: {date}
TXT;
    }
    return '';
}

function legal_text(string $slug): string
{
    $t = trim((string)setting('legal_' . $slug));
    return $t !== '' ? $t : legal_default($slug);
}

/** Placeholders + simple markup → safe HTML. */
function legal_render(string $text): string
{
    $email = setting('site_email') ?: '';
    $rep = ['{site}' => setting('site_name', 'PATADOCS'), '{url}' => url(''), '{email}' => $email !== '' ? $email : '(see the Contact page)',
        '{phone}' => setting('site_phone') ? '· ' . setting('site_phone') : '', '{date}' => date('j F Y', (int)(setting('legal_updated') ?: time()))];
    $text = strtr($text, $rep);
    $link = function (string $s): string {
        $s = e($s);
        return preg_replace('#(https?://[^\s<]+[^\s<.,;:)])#', '<a href="$1" rel="noopener" target="_blank">$1</a>', $s);
    };
    $html = ''; $list = false;
    foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
        $line = rtrim($line);
        if (preg_match('/^-\s+(.+)$/', $line, $m)) { if (!$list) { $html .= '<ul>'; $list = true; } $html .= '<li>' . $link($m[1]) . '</li>'; continue; }
        if ($list) { $html .= '</ul>'; $list = false; }
        if ($line === '') { continue; }
        if (preg_match('/^##\s+(.+)$/', $line, $m)) { $html .= '<h2>' . e($m[1]) . '</h2>'; continue; }
        $html .= '<p>' . $link($line) . '</p>';
    }
    return $html . ($list ? '</ul>' : '');
}

/** Renders a legal page (called by privacy-policy.php, terms.php, ...). */
function legal_page(string $slug): void
{
    $pages = legal_pages();
    if (!isset($pages[$slug])) { abort_page(404, 'Page not found', 'This page does not exist.'); }
    [$title, $desc] = $pages[$slug];
    $canon = page_url($slug);
    $meta = ['title' => $title, 'description' => $desc . ' — ' . setting('site_name'), 'canonical' => $canon, 'nav' => '', 'no_ads' => true,
        'schema' => [['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $title, 'url' => $canon, 'inLanguage' => 'en-KE',
            'dateModified' => date('c', (int)(setting('legal_updated') ?: time()))], breadcrumb_schema([['Home', url('')], [$title, null]])]];
    include ROOT_DIR . '/includes/header.php';
    echo '<section class="page-section active" id="page-legal">' . breadcrumb_html([['Home', url('')], [$title, null]])
        . '<div class="panel"><div class="panel-header">' . e(mb_strtoupper($title)) . '</div><div class="panel-body legal-text">'
        . '<h1 class="section-title" style="margin-top:0;">' . e($title) . '</h1>' . legal_render(legal_text($slug))
        . '<p class="help" style="margin-top:18px;">Other policies: ';
    $links = [];
    foreach ($pages as $s => $p) { if ($s !== $slug) { $links[] = '<a href="' . e(page_url($s)) . '">' . e($p[0]) . '</a>'; } }
    echo implode(' · ', $links) . '</p></div></div></section>';
    include ROOT_DIR . '/includes/footer.php';
}
