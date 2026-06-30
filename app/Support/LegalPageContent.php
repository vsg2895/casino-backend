<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Brand-aware generator for the standard legal / informational pages every
 * casino-affiliate site must publish.
 *
 * Content is production-ready and written to satisfy:
 *  - Major affiliate programs' compliance expectations (transparency, editorial
 *    independence, responsible gambling, 18+).
 *  - Email service providers (SendGrid, Mailgun, Amazon SES, Postmark) that
 *    require a public Privacy Policy, Terms, clear sender identity, consent and
 *    one-click unsubscribe before approving a sending domain.
 *  - GDPR / UK GDPR and CCPA/CPRA disclosure requirements.
 *
 * Brand-specific facts that genuinely require human/legal input (legal entity
 * name, company registration number, registered postal address, governing-law
 * jurisdiction) are left as clearly bracketed placeholders for the operator to
 * complete; everything else is finished copy.
 *
 * Used by {@see \Database\Seeders\CmsPageSeeder} and by site registration so a
 * newly added domain automatically receives the full set of pages.
 */
final class LegalPageContent
{
    /**
     * Build every standard page for a brand.
     *
     * @return array<int, array{slug:string,title:string,meta_title:string,meta_description:string,status:string,content:string}>
     */
    public static function forBrand(string $brand, string $domain): array
    {
        $url      = 'https://' . $domain;
        $support  = 'support@' . $domain;
        $privacy  = 'privacy@' . $domain;
        $dpo      = 'dpo@' . $domain;
        $legal    = 'legal@' . $domain;
        $compliance = 'compliance@' . $domain;
        $partners = 'partners@' . $domain;
        $company  = '[Legal Entity Name]';
        $address  = '[Registered business address]';
        $jurisdiction = '[governing-law jurisdiction]';

        $pages = [];

        // ── About Us ──────────────────────────────────────────────────────────
        $pages[] = [
            'slug'             => 'about',
            'title'            => 'About Us',
            'meta_title'       => "About {$brand} — Independent Casino Reviews",
            'meta_description' => "Learn who we are at {$brand}, how we independently review and rate online casinos, and our commitment to safe, responsible play.",
            'content'          => <<<HTML
<p>{$brand} is an independent online-casino comparison and review platform. We help players discover licensed casino brands, understand bonus offers, and make informed choices. We are <strong>not a gambling operator</strong>: you cannot place bets, deposit, or gamble on {$brand}, and we never handle player funds.</p>

<h2>What We Do</h2>
<p>Our team researches, tests, and reviews online casinos so you don't have to. We compare licensing, security, game range, banking options, customer support, and bonus value, then publish clear, honest summaries to help you compare your options at a glance.</p>

<h2>Our Review Methodology</h2>
<p>Every casino featured on {$brand} is assessed against a consistent, documented checklist:</p>
<ul>
  <li><strong>Licensing &amp; regulation</strong> — we prioritise operators licensed by recognised authorities.</li>
  <li><strong>Safety &amp; security</strong> — SSL encryption, data protection, and account-protection tools.</li>
  <li><strong>Game fairness</strong> — use of audited Random Number Generators (RNGs) and reputable software providers.</li>
  <li><strong>Banking</strong> — deposit/withdrawal methods, processing times, and transparency of limits.</li>
  <li><strong>Bonuses</strong> — real value of promotions, including wagering requirements and terms.</li>
  <li><strong>Customer support</strong> — availability, responsiveness, and quality.</li>
  <li><strong>Responsible gambling</strong> — the safer-gambling tools each operator provides.</li>
</ul>

<h2>Editorial Independence</h2>
<p>Our ratings are editorially independent. While {$brand} is funded through affiliate partnerships (see our <a href="{$url}/affiliate-disclosure">Affiliate Disclosure</a>), <strong>operators cannot pay for a higher rating or a better review</strong>. Commercial relationships never determine our scores.</p>

<h2>Our Commitment to Players</h2>
<p>We promote gambling strictly as a form of entertainment for adults aged <strong>18 or older</strong> (or the legal age in your jurisdiction). Please read our <a href="{$url}/responsible-gambling">Responsible Gambling</a> page and always play within your means.</p>

<h2>Contact</h2>
<p>Questions or feedback? Email us at <a href="mailto:{$support}">{$support}</a> or visit our <a href="{$url}/contact">Contact</a> page.</p>
HTML,
        ];

        // ── Contact Us ────────────────────────────────────────────────────────
        $pages[] = [
            'slug'             => 'contact',
            'title'            => 'Contact Us',
            'meta_title'       => "Contact {$brand}",
            'meta_description' => "Get in touch with the {$brand} team for support, privacy requests, partnerships, or feedback.",
            'content'          => <<<HTML
<p>We'd love to hear from you. Whether you have a question, a correction, a privacy request, or a partnership enquiry, please use the most relevant contact below and we'll get back to you.</p>

<h2>General &amp; Support</h2>
<p>For general questions and feedback: <a href="mailto:{$support}">{$support}</a></p>

<h2>Privacy &amp; Data Requests</h2>
<p>To exercise your data-protection rights or ask about how we handle personal data: <a href="mailto:{$privacy}">{$privacy}</a> (Data Protection contact: <a href="mailto:{$dpo}">{$dpo}</a>). See our <a href="{$url}/privacy-policy">Privacy Policy</a>.</p>

<h2>Partnerships &amp; Operators</h2>
<p>For affiliate, advertising, and business enquiries: <a href="mailto:{$partners}">{$partners}</a></p>

<h2>Postal Address</h2>
<p>{$brand} is operated by {$company}, {$address}.</p>

<h2>Response Times</h2>
<p>We aim to respond to all enquiries within 2–3 business days. Privacy requests are handled within the timeframes required by applicable law.</p>

<h2>Newsletter</h2>
<p>If you subscribed to our newsletter and wish to stop receiving emails, you can unsubscribe at any time using the link in the footer of any email we send. See our <a href="{$url}/privacy-policy">Privacy Policy</a> for details on email communications.</p>
HTML,
        ];

        // ── Privacy Policy ────────────────────────────────────────────────────
        $pages[] = [
            'slug'             => 'privacy-policy',
            'title'            => 'Privacy Policy',
            'meta_title'       => "Privacy Policy — {$brand}",
            'meta_description' => "How {$brand} collects, uses, shares, and protects your personal data, your GDPR and CCPA/CPRA rights, and how to contact us.",
            'content'          => <<<HTML
<p>This Privacy Policy explains how {$brand} ("we", "us", "our") collects, uses, discloses, and safeguards your personal data when you visit <a href="{$url}">{$domain}</a> or subscribe to our communications. {$brand} is operated by {$company}, {$address}, which is the data controller responsible for your personal data.</p>

<h2>1. Information We Collect</h2>
<h3>Information you provide</h3>
<ul>
  <li><strong>Email address</strong> — when you subscribe to our newsletter or contact us.</li>
  <li><strong>Messages</strong> — the content of any enquiry you send us.</li>
</ul>
<h3>Information collected automatically</h3>
<ul>
  <li><strong>Device &amp; usage data</strong> — IP address, browser type, pages viewed, and referring URLs.</li>
  <li><strong>Cookies &amp; similar technologies</strong> — see our <a href="{$url}/cookie-policy">Cookie Policy</a>.</li>
  <li><strong>Affiliate attribution data</strong> — when you click an outbound offer, a referral/click identifier may be passed to the operator so the referral can be attributed.</li>
</ul>

<h2>2. How and Why We Use Your Data</h2>
<p>We process personal data to operate and improve our website, send communications you have requested, measure traffic, ensure security, and comply with legal obligations. Where GDPR applies, our lawful bases are:</p>
<ul>
  <li><strong>Consent</strong> — for newsletters, marketing emails, and non-essential cookies.</li>
  <li><strong>Legitimate interests</strong> — for analytics, site security, and fraud prevention, balanced against your rights.</li>
  <li><strong>Legal obligation</strong> — where we must retain or disclose data by law.</li>
</ul>

<h2>3. Email &amp; Marketing Communications</h2>
<p>We only send newsletter and marketing emails to people who have <strong>opted in</strong>. Every email includes a clear, <strong>one-click unsubscribe</strong> link, and we honour opt-out requests promptly. We do <strong>not sell or rent</strong> your email address. We use reputable third-party email service providers (such as SendGrid, Mailgun, Amazon SES, or Postmark) to deliver these messages on our behalf as data processors under appropriate agreements.</p>

<h2>4. Cookies &amp; Tracking</h2>
<p>We use cookies and similar technologies for essential functionality, analytics, and affiliate attribution. You can manage your preferences via our consent banner, your browser settings, or a <strong>Global Privacy Control (GPC)</strong> signal. Full details are in our <a href="{$url}/cookie-policy">Cookie Policy</a>.</p>

<h2>5. How We Share Your Data</h2>
<p>We share personal data only with:</p>
<ul>
  <li><strong>Service providers</strong> — analytics, hosting, and email delivery partners acting as processors.</li>
  <li><strong>Affiliate operators</strong> — limited attribution data when you choose to click through to their site.</li>
  <li><strong>Authorities</strong> — where required by law or to protect our rights.</li>
</ul>
<p>We never sell your personal data.</p>

<h2>6. International Data Transfers</h2>
<p>Your data may be processed outside your country. Where it is, we rely on appropriate safeguards such as the European Commission's Standard Contractual Clauses (SCCs) or equivalent mechanisms.</p>

<h2>7. Data Retention</h2>
<p>We keep personal data only as long as necessary for the purposes described above. Newsletter data is retained until you unsubscribe; analytics data is retained for a limited period and then deleted or anonymised.</p>

<h2>8. Your Rights (GDPR / UK GDPR)</h2>
<p>If you are in the EEA or UK, you have the right to access, rectify, erase, restrict, or port your data, to object to processing, and to withdraw consent at any time. You may also lodge a complaint with your local supervisory authority.</p>

<h2>9. Your Rights (California — CCPA/CPRA)</h2>
<p>California residents have the right to know, delete, and correct personal information, and to opt out of the "sale" or "sharing" of personal information. We honour <strong>Global Privacy Control (GPC)</strong> signals as a valid opt-out and do not discriminate against you for exercising your rights.</p>

<h2>10. Children's Privacy</h2>
<p>Our content is intended for adults aged 18 or older. We do not knowingly collect data from minors. If you believe a minor has provided us data, contact us and we will delete it.</p>

<h2>11. Security</h2>
<p>We use technical and organisational measures, including encryption in transit (SSL/TLS), to protect your data. No method of transmission is completely secure, but we work to protect your information.</p>

<h2>12. Changes to This Policy</h2>
<p>We may update this Policy from time to time. Please review it periodically for any changes.</p>

<h2>13. Contact</h2>
<p>For any privacy question or request, contact <a href="mailto:{$privacy}">{$privacy}</a> or our Data Protection contact at <a href="mailto:{$dpo}">{$dpo}</a>.</p>
HTML,
        ];

        // ── Terms & Conditions ────────────────────────────────────────────────
        $pages[] = [
            'slug'             => 'terms',
            'title'            => 'Terms &amp; Conditions',
            'meta_title'       => "Terms & Conditions — {$brand}",
            'meta_description' => "The terms governing your use of {$brand}, including our affiliate relationship, content accuracy, and limitations of liability.",
            'content'          => <<<HTML
<p>These Terms &amp; Conditions ("Terms") govern your use of <a href="{$url}">{$domain}</a> (the "Site"), operated by {$company}. By accessing or using the Site, you agree to these Terms. If you do not agree, please do not use the Site.</p>

<h2>1. Eligibility</h2>
<p>You must be at least <strong>18 years old</strong> (or the legal gambling age in your jurisdiction) to use the Site. It is your responsibility to ensure that online gambling is legal where you live; laws vary by country, state, and region.</p>

<h2>2. Informational Purpose Only</h2>
<p>{$brand} is an independent information and comparison service. We are <strong>not a casino, bookmaker, or gambling operator</strong>. You cannot gamble, deposit, or wager on the Site, and we do not process payments or hold funds.</p>

<h2>3. Affiliate Relationship</h2>
<p>We may earn a commission when you register or deposit with an operator via links on our Site, at no additional cost to you. This does not influence our editorial ratings. See our <a href="{$url}/affiliate-disclosure">Affiliate Disclosure</a>.</p>

<h2>4. Accuracy of Information</h2>
<p>We work hard to keep information accurate and up to date, but bonuses, terms, and availability change frequently and vary by region. Content is provided "as is" without warranties of any kind. Always verify the current terms directly with the operator before registering.</p>

<h2>5. Third-Party Operators &amp; Links</h2>
<p>The Site contains links to third-party operators we do not own or control. We are not responsible for their content, offers, terms, or conduct. Your dealings with any operator are solely between you and that operator.</p>

<h2>6. Intellectual Property</h2>
<p>All content on the Site, including text, graphics, logos, and design, is owned by or licensed to {$company} and protected by applicable laws. You may not reproduce or redistribute it without permission.</p>

<h2>7. Acceptable Use</h2>
<p>You agree not to misuse the Site, attempt to disrupt it, scrape it without permission, or use it for unlawful purposes.</p>

<h2>8. Limitation of Liability</h2>
<p>To the maximum extent permitted by law, {$company} shall not be liable for any indirect, incidental, or consequential damages, or for any losses arising from your use of the Site or any third-party operator.</p>

<h2>9. Indemnification</h2>
<p>You agree to indemnify and hold harmless {$company} from any claims arising out of your misuse of the Site or breach of these Terms.</p>

<h2>10. Governing Law</h2>
<p>These Terms are governed by the laws of {$jurisdiction}, without regard to conflict-of-law principles.</p>

<h2>11. Changes</h2>
<p>We may update these Terms from time to time. Continued use of the Site after changes constitutes acceptance of the revised Terms.</p>

<h2>12. Contact</h2>
<p>Questions about these Terms? Contact <a href="mailto:{$legal}">{$legal}</a>.</p>
HTML,
        ];

        // ── Cookie Policy ─────────────────────────────────────────────────────
        $pages[] = [
            'slug'             => 'cookie-policy',
            'title'            => 'Cookie Policy',
            'meta_title'       => "Cookie Policy — {$brand}",
            'meta_description' => "What cookies {$brand} uses, why we use them, and how you can manage or opt out of non-essential cookies.",
            'content'          => <<<HTML
<p>This Cookie Policy explains how {$brand} uses cookies and similar technologies on <a href="{$url}">{$domain}</a>, and how you can control them.</p>

<h2>What Are Cookies?</h2>
<p>Cookies are small text files stored on your device when you visit a website. They help the site function, remember your preferences, and understand how it is used.</p>

<h2>Categories of Cookies We Use</h2>
<ul>
  <li><strong>Strictly necessary</strong> — required for the Site to work (e.g. security and basic navigation). These cannot be switched off.</li>
  <li><strong>Analytics / performance</strong> — help us measure traffic and improve content (e.g. Google Analytics).</li>
  <li><strong>Functional</strong> — remember your preferences and choices.</li>
  <li><strong>Marketing / affiliate</strong> — UTM parameters and affiliate click identifiers used to attribute referrals to partner operators.</li>
</ul>

<h2>Managing Your Preferences</h2>
<p>When required, we ask for your consent to non-essential cookies via a consent banner. You can change your choices at any time by:</p>
<ul>
  <li>Updating your preferences in our consent banner;</li>
  <li>Adjusting your browser settings to block or delete cookies;</li>
  <li>Enabling a <strong>Global Privacy Control (GPC)</strong> signal, which we honour as an opt-out.</li>
</ul>
<p>Please note that blocking some cookies may affect how the Site works.</p>

<h2>Third-Party Cookies</h2>
<p>Some cookies are set by third parties (such as analytics providers and affiliate operators). Their use of cookies is governed by their own privacy and cookie policies.</p>

<h2>More Information</h2>
<p>For how we handle the data collected via cookies, see our <a href="{$url}/privacy-policy">Privacy Policy</a>, or contact <a href="mailto:{$privacy}">{$privacy}</a>.</p>
HTML,
        ];

        // ── Responsible Gambling ──────────────────────────────────────────────
        $pages[] = [
            'slug'             => 'responsible-gambling',
            'title'            => 'Responsible Gambling',
            'meta_title'       => "Responsible Gambling — {$brand}",
            'meta_description' => "Gamble responsibly. {$brand} provides safer-gambling guidance, warning signs, self-exclusion tools, and links to professional support.",
            'content'          => <<<HTML
<p>At {$brand}, we believe gambling should always be a fun, controlled form of entertainment — never a way to make money or escape problems. This page provides guidance and resources to help you stay in control.</p>

<h2>18+ Only</h2>
<p>Gambling is strictly for adults aged <strong>18 or older</strong> (or the legal age in your jurisdiction). Underage gambling is illegal and harmful.</p>

<h2>Signs of Problem Gambling</h2>
<ul>
  <li>Spending more time or money than you can afford;</li>
  <li>Chasing losses or borrowing money to gamble;</li>
  <li>Gambling to cope with stress or low mood;</li>
  <li>Neglecting work, family, or responsibilities;</li>
  <li>Feeling anxious or guilty about your gambling.</li>
</ul>

<h2>Tips to Stay in Control</h2>
<ul>
  <li>Set a budget and a time limit before you play — and stick to them;</li>
  <li>Never chase losses;</li>
  <li>Only gamble with money you can afford to lose;</li>
  <li>Take regular breaks;</li>
  <li>Don't gamble while upset or under the influence.</li>
</ul>

<h2>Tools That Can Help</h2>
<p>Licensed operators offer safer-gambling tools including <strong>deposit limits, loss limits, session reminders, cooling-off periods, and self-exclusion</strong>. We encourage you to use them.</p>

<h2>Self-Exclusion &amp; Blocking</h2>
<ul>
  <li><strong>GAMSTOP</strong> (UK) — free self-exclusion from licensed sites: <a href="https://www.gamstop.co.uk" rel="nofollow noopener" target="_blank">gamstop.co.uk</a></li>
  <li><strong>Gamban</strong> — software that blocks gambling sites and apps: <a href="https://gamban.com" rel="nofollow noopener" target="_blank">gamban.com</a></li>
</ul>

<h2>Get Help &amp; Support</h2>
<ul>
  <li><strong>GamCare</strong> — <a href="https://www.gamcare.org.uk" rel="nofollow noopener" target="_blank">gamcare.org.uk</a></li>
  <li><strong>BeGambleAware</strong> — <a href="https://www.begambleaware.org" rel="nofollow noopener" target="_blank">begambleaware.org</a></li>
  <li><strong>Gamblers Anonymous</strong> — <a href="https://www.gamblersanonymous.org" rel="nofollow noopener" target="_blank">gamblersanonymous.org</a></li>
  <li><strong>Gambling Therapy</strong> (worldwide) — <a href="https://www.gamblingtherapy.org" rel="nofollow noopener" target="_blank">gamblingtherapy.org</a></li>
  <li><strong>1-800-GAMBLER</strong> (US) — call 1-800-426-2537</li>
</ul>

<h2>Protecting Minors</h2>
<p>If children have access to your device, consider parental-control and blocking software to prevent underage access to gambling content.</p>

<p>If gambling stops being fun, please reach out for support. Help is available, and recovery is possible.</p>
HTML,
        ];

        // ── Affiliate Disclosure ──────────────────────────────────────────────
        $pages[] = [
            'slug'             => 'affiliate-disclosure',
            'title'            => 'Affiliate Disclosure',
            'meta_title'       => "Affiliate Disclosure — {$brand}",
            'meta_description' => "{$brand} is reader-supported. We may earn commissions from some links at no extra cost to you — and it never affects our independent ratings.",
            'content'          => <<<HTML
<p>In the interest of full transparency and in line with the U.S. Federal Trade Commission (FTC) guidelines and similar standards worldwide, this page explains how {$brand} makes money.</p>

<h2>We Are Reader-Supported</h2>
<p>{$brand} is funded through affiliate partnerships. When you click certain links on our Site and then register or deposit with an operator, <strong>we may earn a commission — at no extra cost to you</strong>.</p>

<h2>It Costs You Nothing Extra</h2>
<p>Affiliate commissions are paid by the operator, not by you. You never pay more for using our links, and you often access the same or better promotions.</p>

<h2>Our Independence</h2>
<p>Commissions help us run the Site, but they <strong>do not influence our ratings or reviews</strong>. Operators cannot buy a higher score, and we only feature brands we believe offer genuine value to players.</p>

<h2>How to Identify Affiliate Links</h2>
<p>Outbound links to operators are marked with <code>rel="sponsored nofollow"</code> and open in a new tab, consistent with search-engine and advertising best practices.</p>

<h2>Questions</h2>
<p>If you have any questions about our affiliate relationships, contact <a href="mailto:{$support}">{$support}</a>.</p>
HTML,
        ];

        // ── Disclaimer ────────────────────────────────────────────────────────
        $pages[] = [
            'slug'             => 'disclaimer',
            'title'            => 'Disclaimer',
            'meta_title'       => "Disclaimer — {$brand}",
            'meta_description' => "Important disclaimers about the information published on {$brand}, including accuracy, gambling risk, and third-party content.",
            'content'          => <<<HTML
<p>The information on <a href="{$url}">{$domain}</a> is provided for general informational and entertainment purposes only. By using the Site, you accept the following disclaimers.</p>

<h2>No Professional Advice</h2>
<p>Nothing on the Site constitutes legal, financial, or professional advice. You should not rely on our content as a substitute for advice from a qualified professional.</p>

<h2>Accuracy &amp; Availability</h2>
<p>Bonuses, odds, terms, and operator availability change frequently and differ by region. While we strive for accuracy, we make no guarantee that information is current, complete, or error-free. Always confirm details directly with the operator.</p>

<h2>Gambling Involves Risk</h2>
<p>Gambling carries financial risk and there is <strong>no guaranteed way to win</strong>. Never gamble more than you can afford to lose. Please read our <a href="{$url}/responsible-gambling">Responsible Gambling</a> page.</p>

<h2>Legality Is Your Responsibility</h2>
<p>Online gambling is not legal everywhere. It is your responsibility to ensure that participating in online gambling is lawful in your jurisdiction before registering with any operator.</p>

<h2>Third-Party Content</h2>
<p>The Site links to third-party operators and resources we do not control. We are not responsible for their content, offers, or practices, and a link does not imply endorsement of everything on that site.</p>

<h2>Bonus Terms Apply</h2>
<p>All promotions are subject to the operator's terms and conditions, including wagering requirements, eligibility, and expiry. Review them carefully before opting in.</p>

<h2>Contact</h2>
<p>Questions about this Disclaimer? Contact <a href="mailto:{$support}">{$support}</a>.</p>
HTML,
        ];

        // ── AML Policy ────────────────────────────────────────────────────────
        $pages[] = [
            'slug'             => 'aml-policy',
            'title'            => 'AML Policy',
            'meta_title'       => "Anti-Money Laundering (AML) Policy — {$brand}",
            'meta_description' => "{$brand}'s position on anti-money laundering: we are an informational affiliate that holds no funds, and we promote only licensed operators with AML programs.",
            'content'          => <<<HTML
<p>This Anti-Money Laundering (AML) statement explains {$brand}'s role and our commitment to lawful, transparent operation.</p>

<h2>Our Role</h2>
<p>{$brand} is an <strong>informational affiliate and comparison website</strong>. We do <strong>not</strong> accept deposits, process payments, exchange or convert currency (including cryptocurrency), or hold customer funds. Because we do not provide financial or gambling services, we are not a financial institution or gambling operator subject to AML/CFT licensing obligations in our own right.</p>

<h2>Our Commitment</h2>
<p>Despite our limited role, we are committed to supporting the integrity of the industry and to AML best practice. Specifically, we:</p>
<ul>
  <li>Promote <strong>only licensed operators</strong> that are required to maintain their own AML and counter-terrorist-financing (CFT) programs;</li>
  <li>Do not knowingly facilitate, promote, or benefit from any unlawful activity;</li>
  <li>Cooperate with law-enforcement and regulatory authorities where lawfully required.</li>
</ul>

<h2>Operator Responsibilities</h2>
<p>The licensed operators we feature are responsible for customer due diligence, transaction monitoring, source-of-funds checks, and suspicious-activity reporting under the regulations that apply to them. When you register and transact with an operator, their AML procedures apply.</p>

<h2>Reporting Concerns</h2>
<p>If you have concerns about money laundering or suspicious activity related to a brand featured on our Site, contact <a href="mailto:{$compliance}">{$compliance}</a> and, where appropriate, report it to the relevant operator and authorities.</p>
HTML,
        ];

        // ── KYC Policy ────────────────────────────────────────────────────────
        $pages[] = [
            'slug'             => 'kyc-policy',
            'title'            => 'KYC Policy',
            'meta_title'       => "Know Your Customer (KYC) Policy — {$brand}",
            'meta_description' => "How identity verification (KYC) works in online gambling, and why {$brand}, as an affiliate, does not collect identity documents.",
            'content'          => <<<HTML
<p>This page explains how "Know Your Customer" (KYC) identity verification works in online gambling and clarifies {$brand}'s role.</p>

<h2>{$brand} Does Not Perform KYC</h2>
<p>{$brand} does not open player accounts, process payments, or facilitate gambling transactions. As a result, <strong>we do not collect or verify identity documents</strong> (such as passports, ID cards, or proof of address) and we do not perform KYC checks on players.</p>

<h2>Operators Perform KYC</h2>
<p>The licensed operators we feature are legally required to verify the identity of their customers. As part of registration or before a withdrawal, an operator may ask you to provide:</p>
<ul>
  <li>Proof of identity (government-issued ID);</li>
  <li>Proof of address (e.g. a utility bill or bank statement);</li>
  <li>Proof of payment method.</li>
</ul>

<h2>Why KYC Exists</h2>
<p>KYC protects players and the industry by preventing fraud, underage gambling, and money laundering, and by ensuring funds are paid to the rightful account holder. It is a standard, legitimate part of using a regulated operator.</p>

<h2>The Data We Do Collect</h2>
<p>The only personal data {$brand} collects directly is the information you choose to share with us — for example, your email address when you subscribe to our newsletter. We handle that data as described in our <a href="{$url}/privacy-policy">Privacy Policy</a>.</p>

<h2>Contact</h2>
<p>Questions? Contact <a href="mailto:{$privacy}">{$privacy}</a>.</p>
HTML,
        ];

        // ── Editorial Policy ──────────────────────────────────────────────────
        $pages[] = [
            'slug'             => 'editorial-policy',
            'title'            => 'Editorial Policy',
            'meta_title'       => "Editorial Policy — {$brand}",
            'meta_description' => "How {$brand} researches, reviews, rates, and updates online-casino content — and how we keep our editorial process independent and trustworthy.",
            'content'          => <<<HTML
<p>This Editorial Policy describes the standards behind every review, rating, and guide we publish on {$brand}. Our goal is to provide accurate, honest, and useful information that helps players make safer choices.</p>

<h2>How We Research &amp; Review</h2>
<p>Our reviewers evaluate each operator against a consistent checklist covering licensing, security, game fairness, banking, bonus value, customer support, and responsible-gambling tools. Where possible, we base our assessments on first-hand testing and verifiable public information.</p>

<h2>How We Rate</h2>
<p>Ratings reflect the overall quality and safety of an operator, weighting factors such as regulation, transparency, and player protection most heavily. A strong bonus alone will never earn a high score if an operator falls short on safety or fairness.</p>

<h2>Editorial Independence</h2>
<p>{$brand} is funded through affiliate partnerships (see our <a href="{$url}/affiliate-disclosure">Affiliate Disclosure</a>), but <strong>commercial relationships never determine our ratings</strong>. Operators cannot pay for a better review, and advertising is kept separate from editorial content.</p>

<h2>Accuracy &amp; Updates</h2>
<p>The online-gambling landscape changes quickly. We review and update our content regularly, and we encourage readers to verify current terms directly with operators. If you spot an error, please tell us.</p>

<h2>Corrections</h2>
<p>We correct significant errors promptly and transparently. To report an inaccuracy, email <a href="mailto:{$support}">{$support}</a>.</p>
HTML,
        ];

        // Stamp common metadata and publish status on every page.
        return array_map(static function (array $page): array {
            $page['status'] = 'published';

            return $page;
        }, $pages);
    }

    /** The canonical ordered list of standard page slugs. */
    public static function slugs(): array
    {
        return [
            'about', 'contact', 'privacy-policy', 'terms', 'cookie-policy',
            'responsible-gambling', 'affiliate-disclosure', 'disclaimer',
            'aml-policy', 'kyc-policy', 'editorial-policy',
        ];
    }
}
