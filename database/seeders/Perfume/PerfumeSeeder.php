<?php

declare(strict_types=1);

namespace Database\Seeders\Perfume;

use App\Perfume\Models\Brand;
use App\Perfume\Models\Category;
use App\Perfume\Models\HomeCard;
use App\Perfume\Models\NavItem;
use App\Perfume\Models\Page;
use App\Perfume\Models\Perfumer;
use App\Perfume\Models\Product;
use App\Perfume\Models\ScentFamily;
use App\Perfume\Models\ScentNote;
use App\Perfume\Models\Store;
use App\Perfume\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The sample content of docs/design/{Main,Catalog,Product}.dc.html, so the
 * storefront renders fully right after setup.
 *
 * NOT in DatabaseSeeder (that file is the casino platform's). Run it alone:
 *
 *   php artisan db:seed --class="Database\Seeders\Perfume\PerfumeSeeder"
 *
 * Idempotent: every row is matched by slug / label and updated, so a second
 * run changes nothing an admin typed into OTHER columns… except the fields
 * listed here, which it resets. Bracketed facts in the design ([PRICE],
 * [PHONE], [SKU]…) are left EMPTY on purpose — the storefront hides them.
 *
 * Scent families and countries for products other than Sauvage are the
 * usual trade classifications; verify them against your distributor's data.
 */
class PerfumeSeeder extends Seeder
{
    /**
     * The shop's own details, written once and used by the settings, the
     * store card and every footer page below — so a phone number can never
     * read one way on the Contacts page and another in the footer.
     */
    private const string PHONE = '+1 646 325 7236';

    private const string PHONE_HREF = '+16463257236';

    private const string EMAIL = 'info@bigglowday.com';

    private const string ADDRESS = '1842 Oakridge Drive, Columbus, OH 43214, United States';

    public function run(): void
    {
        $this->settings();
        $families = $this->scentFamilies();
        $notes = $this->scentNotes();
        $brands = $this->brands();
        $categories = $this->categories();
        $perfumer = Perfumer::query()->updateOrCreate(['slug' => 'francois-demachy'], ['name' => 'François Demachy']);
        $this->products($brands, $categories, $families, $notes, $perfumer);
        $this->homeCards();
        $this->nav();
        $this->stores();
        $this->pages();
    }

    private function settings(): void
    {
        $s = Setting::current();
        $s->fill([
            'site_name'                => 'Big Glow Day',
            'domain'                   => 'bigglowday.com',
            'accent_color'             => '#D6F25C',
            'phone'                    => self::PHONE,
            'email'                    => self::EMAIL,
            // Tokens in braces are filled from the settings; a sentence whose
            // token is empty is dropped by the storefront.
            'topbar_sale_text'         => 'Sale — up to {sale_percent}% off',
            'hero_eyebrow'             => 'Original fragrances · Columbus, OH',
            // *…* marks the italic accent clause.
            'hero_title'               => 'Perfume for her, for him, and for *no one in particular.*',
            'hero_text'                => 'Designer and niche houses in one place. Order a 2 ml sample first, then decide on the full bottle.',
            'hero_cta_primary_label'   => 'Shop new arrivals',
            'hero_cta_primary_url'     => '#new',
            'hero_cta_secondary_label' => 'How samples work',
            'hero_cta_secondary_url'   => '#samples',
            'notes_title'              => 'Start from a note',
            'new_arrivals_title'       => 'New arrivals',
            'samples_title'            => 'Wear it for a week before you buy the bottle.',
            'samples_text'             => 'Most fragrances in the catalog come as a 2 ml sample. Pick up to {samples_max_per_order} per order; the sample price comes off your full bottle.',
            'samples_steps'            => [
                'Add samples to the cart like any product',
                'Test on skin, at home, over several days',
                'Order the bottle with the sample price deducted',
            ],
            'samples_cta_label'        => 'Choose samples',
            'samples_cta_url'          => '/catalog?sample=1',
            'brands_title'             => 'Houses we carry',
            'stores_title'             => 'Visit a store',
            'payment_labels'           => ['Visa', 'Mastercard'],
            'meta_title'               => 'Big Glow Day — original perfume, shipped across the US',
            'meta_description'         => 'Designer and niche fragrances for her and for him, with 2 ml samples to try before the full bottle. Delivered across the United States.',
        ])->save();
    }

    /** @return array<string, ScentFamily> */
    private function scentFamilies(): array
    {
        $out = [];
        foreach (['Woody', 'Citrus', 'Spicy', 'Fougère', 'Aromatic fougère', 'Oriental', 'Floral', 'Fruity'] as $i => $name) {
            $out[$name] = ScentFamily::query()->updateOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'position' => $i]);
        }

        return $out;
    }

    /** @return array<string, ScentNote> */
    private function scentNotes(): array
    {
        $chips = ['Sweet', 'Floral', 'Woody', 'Leather', 'Fruity', 'Citrus'];
        $pyramid = [
            'Calabrian bergamot', 'Pepper', 'Lavender', 'Sichuan pepper', 'Geranium', 'Vetiver', 'Patchouli',
            'Ambroxan', 'Cedar', 'Labdanum',
        ];
        $out = [];
        foreach ([...$chips, ...$pyramid] as $i => $name) {
            $out[$name] = ScentNote::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'featured_on_home' => in_array($name, $chips, true), 'position' => $i],
            );
        }

        return $out;
    }

    /** @return array<string, Brand> */
    private function brands(): array
    {
        $rows = [
            'Amouage' => 'Oman', 'Byredo' => 'Sweden', 'Chanel' => 'France', 'Dior' => 'France',
            'Giorgio Armani' => 'Italy', 'Hermès' => 'France', 'Lancôme' => 'France',
            'Maison Francis Kurkdjian' => 'France', 'Prada' => 'Italy', 'Tom Ford' => 'USA',
            'Versace' => 'Italy', 'Yves Saint Laurent' => 'France', 'Rabanne' => 'Spain',
        ];
        $featured = ['Amouage', 'Byredo', 'Chanel', 'Dior', 'Giorgio Armani', 'Hermès', 'Lancôme', 'Maison Francis Kurkdjian', 'Prada', 'Tom Ford', 'Versace', 'Yves Saint Laurent'];
        $out = [];
        $i = 0;
        foreach ($rows as $name => $country) {
            $out[$name] = Brand::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'country' => $country, 'featured' => in_array($name, $featured, true), 'active' => true, 'position' => $i++],
            );
        }

        return $out;
    }

    /** @return array<string, Category> */
    private function categories(): array
    {
        // THE THREE SECTIONS THE SHOP SELLS, and the only ones in the header.
        // An earlier seed also created Niche, Gift sets, Samples, Cosmetics &
        // care, Jewellery and Gift cards; they are removed below. Every
        // product carries its gender section, so dropping them takes nothing
        // out of the catalog.
        $rows = [
            ['Women', "Women's fragrances"], ['Men', "Men's fragrances"], ['Unisex', 'Unisex fragrances'],
        ];
        $out = [];
        foreach ($rows as $i => [$name, $title]) {
            $out[$name] = Category::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'title' => $title, 'in_header' => true, 'active' => true, 'position' => $i],
            );
        }

        Category::query()
            ->whereIn('slug', ['niche', 'gift-sets', 'samples', 'cosmetics-care', 'jewellery', 'gift-cards'])
            ->get()
            ->each(fn (Category $c) => $c->delete());

        return $out;
    }

    /**
     * @param  array<string, Brand>  $brands
     * @param  array<string, Category>  $categories
     * @param  array<string, ScentFamily>  $families
     * @param  array<string, ScentNote>  $notes
     */
    private function products(array $brands, array $categories, array $families, array $notes, Perfumer $perfumer): void
    {
        // [brand, name, gender, type, full-size ml, tint, family, is_new]
        $rows = [
            ['Dior', 'Sauvage', 'men', 'edt', 100, '#5B6EA8', 'Aromatic fougère', true],
            ['Chanel', 'Coco Mademoiselle', 'women', 'edp', 100, '#E8C9A0', 'Oriental', true],
            ['Yves Saint Laurent', 'Libre', 'women', 'edp', 90, '#D9A441', 'Fougère', true],
            ['Tom Ford', 'Oud Wood', 'unisex', 'edp', 50, '#7A5A44', 'Woody', true],
            ['Amouage', 'Interlude Man', 'men', 'edp', 100, '#3F6FB5', 'Oriental', true],
            ['Giorgio Armani', 'Acqua di Giò', 'men', 'edt', 100, '#9CC7D6', 'Citrus', true],
            ['Lancôme', 'La Vie Est Belle', 'women', 'edp', 75, '#F0B7C4', 'Floral', true],
            ['Byredo', 'Gypsy Water', 'unisex', 'edp', 100, '#D8D5CC', 'Woody', true],
            ['Chanel', 'Bleu de Chanel', 'men', 'edp', 100, '#2F3F6B', 'Woody', false],
            ['Yves Saint Laurent', 'Y', 'men', 'edp', 100, '#4A5E8F', 'Fougère', false],
            ['Versace', 'Eros', 'men', 'edt', 100, '#3FB8B0', 'Fougère', false],
            ['Hermès', 'Terre d’Hermès', 'men', 'edt', 100, '#E08A3C', 'Woody', false],
            ['Tom Ford', 'Noir Extreme', 'men', 'edp', 50, '#8A6A3A', 'Oriental', false],
            ['Rabanne', '1 Million', 'men', 'edt', 100, '#D9B54A', 'Spicy', false],
        ];

        foreach ($rows as $i => [$brand, $name, $gender, $type, $ml, $tint, $family, $isNew]) {
            $isSauvage = $name === 'Sauvage';

            $product = Product::query()->updateOrCreate(
                ['slug' => Str::slug($brand . ' ' . $name)],
                [
                    'brand_id'        => $brands[$brand]->id,
                    'perfumer_id'     => $isSauvage ? $perfumer->id : null,
                    'scent_family_id' => $families[$family]->id,
                    'name'            => $name,
                    'gender'          => $gender,
                    'type'            => $type,
                    'year'            => $isSauvage ? 2015 : null,
                    'country'         => $brands[$brand]->country,
                    'tint'            => $tint,
                    'is_new'          => $isNew,
                    'active'          => true,
                    'position'        => $i,
                ],
            );

            // Its gender section, and only that: those are the three sections.
            $genderCategory = ['women' => 'Women', 'men' => 'Men', 'unisex' => 'Unisex'][$gender];
            $product->categories()->sync([$categories[$genderCategory]->id]);

            // Volumes from the design: Sauvage has the product page's four;
            // everything else its card volume plus a 2 ml sample. No prices,
            // no stock — both are facts the store fills in.
            $product->variants()->delete();
            $volumes = $isSauvage ? [2, 60, 100, 200] : [2, $ml];
            foreach ($volumes as $p => $volume) {
                $product->variants()->create([
                    'volume_ml' => $volume,
                    'label'     => $volume === 2 ? '2 ml sample' : $volume . ' ml',
                    'is_sample' => $volume === 2,
                    'position'  => $p,
                ]);
            }

            if ($isSauvage) {
                $product->notes()->delete();
                $pyramid = [
                    'top'   => ['Calabrian bergamot', 'Pepper'],
                    'heart' => ['Lavender', 'Sichuan pepper', 'Geranium', 'Vetiver', 'Patchouli'],
                    'base'  => ['Ambroxan', 'Cedar', 'Labdanum'],
                ];
                $p = 0;
                foreach ($pyramid as $layer => $names) {
                    foreach ($names as $n) {
                        $product->notes()->create(['scent_note_id' => $notes[$n]->id, 'layer' => $layer, 'position' => $p++]);
                    }
                }
            }
        }
    }

    private function homeCards(): void
    {
        $tiles = [
            ['01', 'For her', '/category/women'],
            ['02', 'For him', '/category/men'],
            ['03', 'Unisex', '/category/unisex'],
        ];

        // Drops the tile an earlier seed pointed at a section that is gone.
        HomeCard::query()->where('kind', 'hero_tile')->whereNotIn('title', array_column($tiles, 1))->delete();
        foreach ($tiles as $i => [$number, $title, $url]) {
            HomeCard::query()->updateOrCreate(
                ['kind' => 'hero_tile', 'title' => $title],
                ['number' => $number, 'url' => $url, 'active' => true, 'position' => $i],
            );
        }

        $services = [
            ['Only originals', 'Every bottle comes from official distributors, with batch code and paperwork on request.', 'Authenticity policy →', '/info/authenticity-policy'],
            ['Delivery across the United States', 'Columbus: {delivery_local_time}, free from {delivery_free_from}. Everywhere else: {delivery_national_time} by post.', 'Delivery and payment →', '/info/delivery-and-payment'],
            // No URL: the bonus system is not built, so the card has no page to lead to.
            ['Bonus points', 'Earn {bonus_percent}% back on every order and spend it on the next one.', 'How the bonus system works →', null],
        ];
        // Drops a card an earlier seed titled for another country.
        HomeCard::query()->where('kind', 'service')->whereNotIn('title', array_column($services, 0))->delete();

        foreach ($services as $i => [$title, $text, $label, $url]) {
            HomeCard::query()->updateOrCreate(
                ['kind' => 'service', 'title' => $title],
                ['text' => $text, 'link_label' => $label, 'url' => $url, 'active' => true, 'position' => $i],
            );
        }
    }

    private function nav(): void
    {
        // The header carries the three categories and nothing beside them, so
        // it holds no nav items at all; an earlier seed's are removed.
        NavItem::query()->where('location', 'header')->delete();

        $footer = [
            'Shop'  => [['Women', '/category/women'], ['Men', '/category/men'], ['Unisex', '/category/unisex'], ['All fragrances', '/catalog']],
            'Help'  => [['Delivery and payment', '/info/delivery-and-payment'], ['Returns', '/info/returns'], ['Authenticity policy', '/info/authenticity-policy'], ['FAQ', '/info/faq'], ['Contacts', '/info/contacts']],
            'About' => [['About the store', '/info/about'], ['Perfumers', '/perfumers'], ['Privacy policy', '/info/privacy-policy']],
        ];
        NavItem::query()->where('location', 'footer')
            ->whereNotIn('label', array_merge(...array_map(fn (array $g) => array_column($g, 0), array_values($footer))))
            ->delete();

        $i = 0;
        foreach ($footer as $group => $items) {
            foreach ($items as $item) {
                NavItem::query()->updateOrCreate(
                    ['location' => 'footer', 'group_label' => $group, 'label' => $item[0]],
                    ['url' => $item[1], 'active' => $item[2] ?? true, 'position' => $i++],
                );
            }
        }
    }

    /** The shop, for the home page's "Visit a store" block. */
    private function stores(): void
    {
        Store::query()->updateOrCreate(
            ['name' => 'Big Glow Day'],
            ['address' => self::ADDRESS, 'active' => true, 'position' => 0],
        );
    }

    private function pages(): void
    {
        $phone = self::PHONE;
        $tel = self::PHONE_HREF;
        $email = self::EMAIL;
        $address = self::ADDRESS;

        $pages = [
            ['Delivery and payment', 'delivery-and-payment', <<<HTML
                <p>We confirm every order by phone before anything is sent, and we take no payment on this website.</p>
                <h2>How ordering works</h2>
                <ol>
                    <li>Add what you want to the cart and fill in the order form. Your name and a phone number are all we need.</li>
                    <li>We call you back to confirm the fragrances, the volumes and the total.</li>
                    <li>We agree delivery and payment with you on that call, then send the parcel.</li>
                </ol>
                <h2>Payment</h2>
                <p>There is no card form on this site, and we never ask for card details by email or message. Payment is arranged with you directly when we confirm the order.</p>
                <h2>Delivery</h2>
                <p>Parcels are sent from our shop at {$address}. How long delivery takes and what it costs depends on where the parcel is going, and we tell you both on the confirmation call, before you commit to anything.</p>
                <h2>Before you order</h2>
                <p>Ask us anything: <a href="tel:{$tel}">{$phone}</a> or <a href="mailto:{$email}">{$email}</a>.</p>
                HTML],

            ['Returns', 'returns', <<<HTML
                <p>If a fragrance is not what you expected, tell us and we will put it right.</p>
                <h2>What we can take back</h2>
                <ul>
                    <li>Bottles that are unopened and still sealed in their original cellophane, with the box undamaged.</li>
                    <li>Anything that arrived damaged, incomplete, or different from what you ordered. Send us a photo and the return is on us.</li>
                </ul>
                <h2>What we cannot</h2>
                <p>An opened bottle can no longer be sold as original, so once the cellophane is off we are not able to take it back. That is exactly why we sell 2 ml samples: wear one for a few days before you commit to a full bottle.</p>
                <h2>How to start a return</h2>
                <p>Call <a href="tel:{$tel}">{$phone}</a> or write to <a href="mailto:{$email}">{$email}</a> with your order number, the <strong>BGD-</strong> code we gave you when you ordered. We will tell you how to send it back and confirm the refund with you.</p>
                <p>Returns are received at {$address}. Please speak to us before posting anything back.</p>
                HTML],

            ['Authenticity policy', 'authenticity-policy', <<<HTML
                <p>Every bottle we sell is the original. We do not stock testers sold as full bottles, refills, decants from unknown hands, or “inspired by” copies.</p>
                <h2>Where our stock comes from</h2>
                <p>We buy from official distributors and authorised wholesalers. Every delivery reaches us sealed, with its batch code intact, and we can show you the paperwork for a bottle on request.</p>
                <h2>What you can check yourself</h2>
                <ul>
                    <li>The cellophane is factory-sealed and sits tight to the box.</li>
                    <li>The batch code is printed on both the box and the bottle, and the two match.</li>
                    <li>The bottle, cap and sprayer are cleanly finished, with no loose or crooked label.</li>
                </ul>
                <p>Ask us for the batch code before you order and we will send it to you.</p>
                <h2>If something looks wrong</h2>
                <p>Stop using the fragrance, keep the box, and call <a href="tel:{$tel}">{$phone}</a> or write to <a href="mailto:{$email}">{$email}</a>. We replace or refund it.</p>
                <h2>Samples</h2>
                <p>Our 2 ml samples come from the same original bottles we sell, and carry the name of the fragrance and the volume on the label.</p>
                HTML],

            ['FAQ', 'faq', <<<HTML
                <h2>How do I order?</h2>
                <p>Add what you want to the cart and fill in the order form with your name and a phone number. We call you back to confirm everything.</p>
                <h2>Do I pay on the website?</h2>
                <p>No. There is no card form here. Payment is agreed with you on the confirmation call.</p>
                <h2>Are the fragrances original?</h2>
                <p>Yes. We buy from official distributors and every bottle reaches us sealed, with its batch code. See the <a href="/info/authenticity-policy">authenticity policy</a>.</p>
                <h2>Can I try something before buying a full bottle?</h2>
                <p>Most fragrances in the catalogue come as a 2 ml sample. Order the samples first, wear them for a few days, then come back for the bottle.</p>
                <h2>I ordered the wrong thing</h2>
                <p>Tell us on the confirmation call — nothing is sent until we have spoken. If the parcel is already on its way, see <a href="/info/returns">returns</a>.</p>
                <h2>How long does delivery take?</h2>
                <p>It depends where the parcel is going. We tell you the time and the cost when we confirm the order.</p>
                <h2>Nobody called me</h2>
                <p>Check the number you left us, then call <a href="tel:{$tel}">{$phone}</a> or write to <a href="mailto:{$email}">{$email}</a> with your <strong>BGD-</strong> order number.</p>
                <h2>How do I stop the emails?</h2>
                <p>Every email we send has an unsubscribe link at the bottom. One click and we stop.</p>
                HTML],

            ['Contacts', 'contacts', <<<HTML
                <p>Call us, write to us, or come to the shop.</p>
                <h2>Phone</h2>
                <p><a href="tel:{$tel}">{$phone}</a></p>
                <h2>Email</h2>
                <p><a href="mailto:{$email}">{$email}</a></p>
                <h2>Address</h2>
                <p>{$address}</p>
                <h2>Asking about an order</h2>
                <p>Have your <strong>BGD-</strong> order number to hand — it is the code shown on screen once you order — and we can find it straight away.</p>
                <h2>Press and wholesale</h2>
                <p>Write to <a href="mailto:{$email}">{$email}</a> and tell us what you need.</p>
                HTML],

            ['About the store', 'about', <<<HTML
                <p>Big Glow Day is a perfume shop. We sell designer and niche fragrances for women, for men, and for anyone who does not much care which of the two a bottle was meant for.</p>
                <h2>What we stock</h2>
                <p>Houses you already know, beside smaller ones worth knowing. The catalogue is deliberately short: every bottle in it is one we would wear ourselves.</p>
                <h2>Try it before the bottle</h2>
                <p>Perfume does not smell the same on paper, in a shop, and on you six hours later. Most of what we sell comes as a 2 ml sample, so you can live with a fragrance for a few days before buying it properly.</p>
                <h2>Originals only</h2>
                <p>Every bottle comes from an official distributor, sealed, with its batch code. The <a href="/info/authenticity-policy">authenticity policy</a> has the detail.</p>
                <h2>Come and see us</h2>
                <p>{$address}<br><a href="tel:{$tel}">{$phone}</a> · <a href="mailto:{$email}">{$email}</a></p>
                HTML],

            ['Privacy policy', 'privacy-policy', <<<HTML
                <p>This page says what we do with your details. It describes this website as it actually works: nothing is collected beyond what is listed here.</p>
                <h2>Who we are</h2>
                <p>Big Glow Day, {$address}. Questions about your data go to <a href="mailto:{$email}">{$email}</a> or <a href="tel:{$tel}">{$phone}</a>.</p>
                <h2>When you place an order</h2>
                <p>We keep the name and phone number you give us, the email address, delivery address and note if you add them, and the fragrances you asked for. We need them to call you back and send the parcel, and we keep the order as our record of the sale.</p>
                <h2>When you subscribe</h2>
                <p>We keep your email address, and your name if you gave one, so that we can write to you about new arrivals. Every email carries an unsubscribe link, and using it stops them for good. You can also ask us to delete the address entirely.</p>
                <h2>Payment details</h2>
                <p>We never see them. There is no card form on this site, and payment is arranged with you directly.</p>
                <h2>What your browser keeps</h2>
                <p>Your cart and your favourites are saved in your own browser, not on our servers — clearing your site data clears them. We use no analytics and no advertising trackers.</p>
                <h2>Who else sees your details</h2>
                <p>Only who must: the courier is given the delivery address, and our email provider handles the messages we send you. We do not sell or share your details with anybody else.</p>
                <h2>Your rights</h2>
                <p>Ask us what we hold about you, ask us to correct it, or ask us to delete it. Write to <a href="mailto:{$email}">{$email}</a> and we will.</p>
                HTML],
        ];

        foreach ($pages as $i => [$title, $slug, $body]) {
            $page = Page::query()->firstOrNew(['slug' => $slug]);
            $page->fill(['title' => $title, 'active' => true, 'position' => $i]);

            if (trim((string) $page->body) === '') {
                $page->body = trim(preg_replace('/^ {16}/m', '', $body));
            }

            $page->save();
        }
    }

}
