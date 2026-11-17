// Turns the raw results into the checks shown in the report, with plain-English copy for business owners.
// Rules for the words: no jargon without explanation, never promise rankings or AI recommendations,
// llms.txt is a proposed standard, Cloudflare bot blocking is a dashboard change, AI answers take weeks to change.

import { BOTS } from './robots.mjs';

const LOCAL_TYPES = new Set(
  'LocalBusiness ProfessionalService HomeAndConstructionBusiness GeneralContractor Electrician Plumber HVACBusiness RoofingContractor HousePainter Locksmith MovingCompany SelfStorage DryCleaningOrLaundry AccountingService LegalService Attorney Notary FinancialService InsuranceAgency RealEstateAgent EmploymentAgency TravelAgency MedicalBusiness Dentist Physiotherapy Optician Pharmacy HealthAndBeautyBusiness BeautySalon HairSalon NailSalon DaySpa TattooParlor FoodEstablishment Restaurant CafeOrCoffeeShop Bakery BarOrPub Winery LodgingBusiness Hotel BedAndBreakfast Campground Store ClothingStore Florist GardenStore HardwareStore HomeGoodsStore JewelryStore PetStore BookStore AutomotiveBusiness AutoRepair AutoDealer AutoBodyShop AutoWash SportsActivityLocation ExerciseGym EntertainmentBusiness ChildCare AnimalShelter Library EducationalOrganization NGO EventVenue CivicStructure PlaceOfWorship Church Museum TouristAttraction School'.split(' ')
);

// Section, title, why it matters. Text for each status is set where the check is worked out.
export const COPY = {
  reachable: { section: 'reach', title: 'Your website loads', why: 'Everything else depends on the site being up and loading normally.' },
  https: { section: 'reach', title: 'Secure address (https)', why: 'Search engines and browsers expect a secure padlock address.' },
  blog_public: { section: 'reach', title: 'Search engines are allowed in', why: 'WordPress has a "discourage search engines" switch. If it is on, the site is hidden from search.' },
  robots_file: { section: 'reach', title: 'Crawler rules can be managed', why: 'An old robots.txt file on the server can override WordPress, so changes made in WordPress have no effect.' },
  robots_rules: { section: 'reach', title: 'Search and AI search tools are welcome', why: 'robots.txt is a short file that tells automated visitors (crawlers) where they may go. AI search tools such as ChatGPT, Claude and Perplexity check it before reading your pages.' },
  cloudflare_robots: { section: 'reach', title: 'Cloudflare settings', why: 'Cloudflare, a service that sits in front of many websites, can add its own rules for AI crawlers. Those can only be changed in the Cloudflare dashboard, not in WordPress.' },
  bot_access: { section: 'reach', title: 'AI tools can actually load your pages', why: 'Even when robots.txt says yes, a firewall or security service can turn AI tools away. We visited your homepage pretending to be each AI tool and compared the result with a normal visit.' },
  sitemap: { section: 'reach', title: 'A list of your pages (sitemap)', why: 'A sitemap lists every page so search engines find new and changed pages quickly.' },
  noindex: { section: 'reach', title: 'Your homepage can appear in search', why: 'A "noindex" tag tells search engines to leave a page out of their results.' },
  schema_business: { section: 'understand', title: 'Your business details in a form machines can read', why: 'Structured data is hidden information in your pages that spells out your business name, type, location, areas and opening hours, so search engines and AI tools don\'t have to guess.' },
  schema_services: { section: 'understand', title: 'Your services spelled out', why: 'Listing each service in structured data helps AI tools match you to questions like "who does X near me?".' },
  business_details: { section: 'understand', title: 'Business summary, services and areas filled in', why: 'These details feed the structured data and the plain-text summary.' },
  llms_txt: { section: 'understand', title: 'A plain-text summary for AI tools (llms.txt)', why: 'llms.txt is a proposed standard: a short plain-text summary of your business and key pages at yoursite/llms.txt. Some AI tools read it and others don\'t yet, but it costs nothing and keeps the facts straight for those that do.' },
  meta_description: { section: 'understand', title: 'Homepage description', why: 'The short summary search engines often show under your link, and a quick signal of what the site is about.' },
  page_descriptions: { section: 'understand', title: 'Descriptions on your other pages', why: 'Each main page benefits from its own short description.' },
  headline: { section: 'understand', title: 'Your main headline is real text', why: 'If the headline is part of a picture or slider image, search engines and AI tools can\'t read it.' },
  verification: { section: 'tools', title: 'Google Search Console and Bing Webmaster Tools', why: 'Free tools from Google and Microsoft that show how your site appears in search and flag problems. Bing matters because ChatGPT and Copilot use Bing\'s index.' },
  indexnow: { section: 'tools', title: 'Instant updates to Bing (IndexNow)', why: 'Tells Bing straight away when you publish or change a page, instead of waiting for it to notice.' },
};

export const SECTIONS = {
  reach: 'Can search engines and AI tools reach your site?',
  understand: 'Can they understand what you do?',
  tools: 'Are the free Google and Bing tools set up?',
};

const TYPE_NAMES = { CivicStructure: 'community building', NGO: 'charity or non-profit', EventVenue: 'event venue', PlaceOfWorship: 'place of worship', HVACBusiness: 'heating and air-conditioning business', LocalBusiness: 'local business' };
const typeName = (t) => TYPE_NAMES[t] || t.replace(/([a-z])([A-Z])/g, '$1 $2').toLowerCase();

const c = (id, status, detail) => ({ id, status, detail, ...COPY[id] });

export function buildChecks({ ext, audit }) {
  const out = [];
  const h = ext.homepage;
  const serverCheck = (id) => audit?.checks?.find((x) => x.id === id);

  out.push(h.code === 200 && !h.challenge ? c('reachable', 'pass', 'Your homepage loads normally.') : c('reachable', 'fail', h.code ? `Your homepage answered with an error (code ${h.code}${h.challenge ? ', a security check page' : ''}).` : `We couldn't reach your homepage (${h.error}).`));
  out.push(ext.site.startsWith('https://') ? c('https', 'pass', 'Your site uses a secure https address.') : c('https', 'warn', 'Your site address starts with http://, not https://.'));

  const bp = serverCheck('blog_public');
  if (bp) out.push(c('blog_public', bp.status === 'pass' ? 'pass' : 'fail', bp.status === 'pass' ? 'The "discourage search engines" switch is off, as it should be.' : 'The "discourage search engines" switch is on, so search engines are told to stay away.'));
  const rf = serverCheck('robots_file');
  if (rf) out.push(c('robots_file', rf.status === 'pass' ? 'pass' : 'warn', rf.status === 'pass' ? 'WordPress controls the crawler rules.' : 'An old robots.txt file on the server overrides WordPress. We back it up and replace it as part of the upgrade.'));

  // robots.txt as the world sees it.
  const blockedSearch = Object.entries(ext.robots.bots).filter(([b, r]) => !r.allowed && BOTS[b].kind === 'search').map(([b]) => b);
  const blockedTraining = Object.entries(ext.robots.bots).filter(([b, r]) => !r.allowed && BOTS[b].kind === 'training').map(([b]) => b);
  const named = Object.entries(ext.robots.bots).filter(([b, r]) => r.group === b).length;
  if (!ext.robots.content) {
    out.push(c('robots_rules', 'warn', `There is no robots.txt file (code ${ext.robots.code}). Crawlers are allowed by default, but there is nothing pointing them to your sitemap.`));
  } else if (blockedSearch.length) {
    out.push(c('robots_rules', 'fail', `Your robots.txt turns away ${blockedSearch.map((b) => `${b} (${BOTS[b].label})`).join(', ')}.`));
  } else {
    out.push(
      c(
        'robots_rules',
        'pass',
        (named ? 'Search and AI search tools are welcomed by name.' : 'Search and AI search tools are allowed by your general rule; none are named.') +
          (blockedTraining.length ? ` Crawlers that collect pages to train AI models are asked to stay out (${blockedTraining.join(', ')}); that doesn't stop you appearing in AI search answers.` : '')
      )
    );
  }
  if (ext.robots.cloudflareManaged || ext.homepage.cloudflare) {
    out.push(
      ext.robots.cloudflareManaged
        ? c('cloudflare_robots', 'warn', 'Cloudflare is adding its own AI rules to your robots.txt. If you want AI tools to read your site, this needs changing in the Cloudflare dashboard (Security → Bots / AI Crawl Control), not in WordPress.')
        : c('cloudflare_robots', 'info', 'Your site is behind Cloudflare. Cloudflare can block AI crawlers ("Block AI bots"); that setting lives in the Cloudflare dashboard, not in WordPress.')
    );
  }

  const blocked = Object.entries(ext.botAccess).filter(([, r]) => r.blocked).map(([b]) => b);
  if (h.code === 200) {
    out.push(
      blocked.length
        ? c('bot_access', 'warn', `Your site may be blocking: ${blocked.join(', ')}. A normal visit loaded, but visits announcing themselves as ${blocked.length > 1 ? 'these tools' : 'this tool'} were refused or challenged. This is a test with borrowed names, so it can't be certain: real crawlers come from known addresses that a firewall may treat differently. Worth checking the firewall or Cloudflare settings.`)
        : c('bot_access', 'pass', `Visits announcing themselves as ${Object.keys(ext.botAccess).length} AI tools all loaded your homepage normally. (This test borrows their names, so it can't fully prove what the real crawlers see.)`)
    );
  }

  out.push(ext.sitemap.url ? c('sitemap', 'pass', `Found at ${ext.sitemap.url}.`) : c('sitemap', 'fail', 'We couldn\'t find a sitemap.'));
  if (h.code === 200) out.push(h.noindex ? c('noindex', 'fail', 'Your homepage tells search engines to leave it out (noindex).') : c('noindex', 'pass', 'Nothing is telling search engines to skip your homepage.'));

  // Understanding.
  const local = h.schema_types.filter((t) => LOCAL_TYPES.has(t));
  if (h.code === 200) {
    if (local.length) out.push(c('schema_business', 'pass', `Your homepage describes you as a ${typeName(local[0])}${h.schema_business?.locality ? ` in ${h.schema_business.locality}` : ''}.`));
    else if (h.schema_types.includes('Organization')) out.push(c('schema_business', 'warn', 'There is a basic organisation entry, but no local business details (type of business, location, areas served, opening hours).'));
    else out.push(c('schema_business', 'fail', h.schema_types.length ? `There is some structured data (${h.schema_types.slice(0, 5).join(', ')}) but nothing describing the business itself.` : 'There is no structured data on your homepage.'));
    out.push(h.schema_types.includes('Service') ? c('schema_services', 'pass', 'Your services are listed in structured data.') : c('schema_services', 'warn', 'Your services aren\'t listed in structured data.'));
  }
  const bd = serverCheck('business_details');
  if (bd) out.push(c('business_details', bd.status === 'pass' ? 'pass' : 'warn', bd.status === 'pass' ? 'Summary, services, areas and contact details are filled in.' : bd.detail));

  out.push(ext.llms.ok ? c('llms_txt', 'pass', `Your site has an llms.txt summary (${ext.llms.lines} lines).`) : c('llms_txt', 'warn', 'Your site has no llms.txt summary.'));

  if (h.code === 200) {
    const d = h.meta_description;
    if (!d) out.push(c('meta_description', 'warn', 'Your homepage has no description, so search engines and AI tools write their own.'));
    else if (d.length < 50) out.push(c('meta_description', 'warn', `Your homepage description is very short: "${d}".`));
    else if (d.length > 170) out.push(c('meta_description', 'warn', `Your homepage description is long and may be cut off: "${d.slice(0, 160)}…".`));
    else out.push(c('meta_description', 'pass', `"${d}"`));
    const h1 = h.h1;
    if (!h1.length) out.push(c('headline', 'warn', 'We couldn\'t find a main headline (an H1 heading) on your homepage. If it\'s part of an image or slider, machines can\'t read it.'));
    else if (!h1[0].text) out.push(c('headline', 'warn', 'Your main headline is an image with no readable words.'));
    else out.push(c('headline', 'pass', `"${h1[0].text.slice(0, 140)}"`));
  }
  const pd = serverCheck('page_descriptions');
  if (pd) out.push(c('page_descriptions', pd.status, pd.detail.replace(/ in (Yoast SEO|Rank Math)\.$/, '.')));

  if (h.code === 200) {
    const both = h.google_verify && h.bing_verify;
    out.push(
      c(
        'verification',
        both ? 'pass' : 'info',
        both
          ? 'Your site is verified with both Google and Bing.'
          : `Verification tags found: Google ${h.google_verify ? 'yes' : 'no'}, Bing ${h.bing_verify ? 'yes' : 'no'}. Sites can also be verified in other ways we can't see from outside, so we'll check this with you.`
      )
    );
  }
  const inow = serverCheck('indexnow');
  if (inow) out.push(c('indexnow', inow.status === 'info' ? 'warn' : inow.status, inow.status === 'info' ? 'Not set up.' : 'Set up: Bing is told whenever a page is published or updated.'));
  return out;
}

export function summarise(checks) {
  const s = { pass: 0, warn: 0, fail: 0, info: 0 };
  for (const x of checks) s[x.status]++;
  return s;
}
