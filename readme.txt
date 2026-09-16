=== BlueBranch Chatbot ===
Contributors: bluebranch
Tags: ai, chatbot, search, assistant, gdpr
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI chat and AI search that answer from your own content only. Hosted in Germany, own model, and the API key never reaches the browser.

== Description ==

Answer visitor questions directly on your WordPress site -- with an AI that knows nothing but your own content.

The plugin hands your published posts and pages to the BlueBranch Chatbot API, which builds a vector knowledge base from them. Questions are answered from exactly that content: as a collapsible chat widget, as an ask field, and as a summarising answer above your search results.

The API key stays on the server. The browser only ever talks to WordPress, and WordPress talks to the API.

= What you get =

* **Chat widget** -- a collapsible chat button, positioned in any corner, with a configurable name, colour, icon, greeting and suggested questions.
* **Search answer** -- a question field that answers below itself without a page reload, sources included. Placed above a set of search results it answers the term that was searched for instead, using the field your theme already has.
* **Trained content screen** -- see what the knowledge base actually holds, delete single entries or all of them, and ask a test question without leaving wp-admin.
* **Editorial control** -- exclude a single post, or a page and its whole branch, from the answers.
* **Automatic clean-up** -- content that stops being public is withdrawn from the index, through WordPress cron. No server cronjob needed.

= How the content is collected =

The plugin **crawls your site over HTTP**, the way a visitor would. What gets trained is therefore what is on the page, including everything the theme renders outside the post content: ACF fields, sections a page builder keeps elsewhere, a block theme's title and meta blocks.

**Your XML sitemap sets the boundary.** What it lists is crawled and nothing else -- no links are followed out of the pages, so the crawl cannot wander into a filter combination or a calendar that invents URLs for ever. The sitemap is found automatically: robots.txt is read first, which is where every SEO plugin announces its own.

If the server is not allowed to call itself -- which happens, and which WordPress has its own Site Health check for -- the plugin falls back to rendering the post content instead of quietly training nothing. The *Train content* screen says up front which of the two will happen.

= Headers, footers, and everything else that stands on every page =

Training the furniture around your content means the chatbot eventually quotes the cookie banner back at somebody. Three passes remove it, each catching what the one before cannot:

1. **The site marks its own furniture.** The crawler sends a signed header, and a request carrying it gets its template parts, menus and sidebars wrapped in a marker -- by WordPress itself, which knows which block is a header. From outside that is guesswork that fails differently on every theme; from inside it is not a guess.
2. **Structure.** A `<main>` narrows the page in one step; otherwise elements that are furniture by role or tag are removed. A `<header>` *inside* an `<article>` is deliberately kept -- that is the entry header, and the title lives there.
3. **Repetition.** A few pages are sampled at the start of a run and anything appearing on most of them is treated as furniture. This is what catches a cookie notice, a breadcrumb bar or a newsletter box under every article -- the things no selector list anticipates because they differ on every site. Removal stops before it would leave a page with too little text.

*Train content → Check one page* shows exactly what would be trained for any address, so you do not have to take it on trust.

= Kept up to date, not resent =

Every change acts on its own entry. Publishing or editing a post re-crawls that one page; unpublishing, trashing, deleting or excluding it withdraws that one entry. Nothing is ever wiped and rebuilt.

A hash of what was last sent is kept per post, so re-running the sitemap crawl costs one API call per page that actually changed, not one per page. Entries are filed under the post ID, so changing a permalink updates the existing entry instead of creating a second one.

Only published, publicly visible entries without a password are ever sent. Posts marked noindex by Yoast SEO, Rank Math or SEOPress are left out, as are posts you exclude yourself.

= Answers, not inventions =

The AI quotes your pages and names its sources. It has no general knowledge to fall back on, so a question your site does not answer gets no answer rather than a plausible-sounding one.

= Privacy and hosting =

* **Hosted in Germany.** Servers and data processing are located in Germany only.
* **Our own AI model.** Your content is not passed on to OpenAI, Anthropic or any other third party.
* **No training on your data.** Neither content nor chat histories are used to train models.
* **Data processing agreement.** A DPA under Art. 28 GDPR is available on request by e-mail to lb@bluebranch.de.
* **No visitor tracking.** The plugin sets no cookies of its own and stores no IP addresses. The rate limiter keeps a salted hash, never the address itself.

== External services ==

This plugin relies on the BlueBranch Chatbot API at `https://api.chatbot.bluebranch.de` to build the knowledge base and to generate answers. Without an account and an API key from that service, the plugin does nothing.

**What is sent, and when:**

* *When a post is published or updated, and during a training run:* the post title, its URL, its content as Markdown, its meta description, a list of frequent words and the language of the site. The content is taken from the page as crawled, with headers, footers, menus, sidebars and repeated blocks removed. This happens only for posts of the types you selected, and only once you have stored an API key. Nothing is sent again while its content is unchanged.
* *When a post stops being public, is deleted, or is excluded:* its identifier (`post_<ID>`), so the entry can be withdrawn.
* *When a visitor asks a question:* the question itself and the language code. When the visitor uses the "summarise this page" button, the visible text of that page is sent with it. Chat mode additionally sends the last few messages of that visitor's own conversation so follow-up questions make sense.
* *On the admin screens:* requests to list, delete and count trained content, and to read the usage tier of your key.

Requests are made by your server, not by the visitor's browser. The visitor's IP address is not forwarded to the API by this plugin.

Service provider: BlueBranch GmbH.
Terms of service: https://chatbot.bluebranch.de/agb
Privacy policy: https://chatbot.bluebranch.de/datenschutz

== Installation ==

1. Install and activate the plugin.
2. Register at [chatbot.bluebranch.de](https://chatbot.bluebranch.de) and create an API key.
3. Store the key under *BlueBranch Chatbot → Settings*.
4. Run *BlueBranch Chatbot → Train content* once, so the AI learns what is already on the site.
5. Switch on *Show the chat button on every page*, or place a block or shortcode where you want it.

== Frequently Asked Questions ==

= Where do I put the chat widget? =

Either switch on *Show the chat button on every page* in the settings, or place the **Chatbot Widget** block, or use the shortcode `[bluebranch_chatbot_widget]`.

= Which shortcodes are there? =

* `[bluebranch_chatbot_widget]` -- the chat button. Accepts `position`, `color`, `name`, `greeting`, `suggestions` (separated by `|`), `hide_summarize`, `hide_disclaimer` and `unstyled`.
* `[bluebranch_chatbot_search]` -- a question field with its answer below it. On a search results page it answers the term that was searched for instead. Accepts `questions` and `suggestions` (separated by `|`), `placeholder`, `button`, `param`, `form` and `autostart`.
* `[bluebranch_chatbot_exclude]…[/bluebranch_chatbot_exclude]` -- the enclosed content stays on the page but is never trained.

= How do I keep a page out of the answers? =

Tick *Exclude from AI answers* in the **BlueBranch Chatbot** box on the post editor. On a hierarchical post type the setting is inherited by every child page, and the affected entries are withdrawn from the knowledge base straight away rather than at the next clean-up.

= Nothing is being trained. Why? =

Training runs through WordPress cron, which only fires when the site is visited. On a quiet development site, run `wp cron event run --due-now`, or use *Train content* to train immediately.

If the *Train content* screen reports that the site cannot fetch its own pages, the host is blocking loopback requests. Either have that allowed, or set *Where the content comes from* to rendered content.

= My sitemap is not found =

Enter its address under *Settings → Sitemap address*. The plugin normally reads robots.txt to find it, which fails if robots.txt is not served or the sitemap is not announced there.

= A page of mine is in the sitemap but is not trained =

Addresses that belong to no single post -- category archives, author pages, the blog home when it lists posts -- are counted and reported but not trained. An entry needs a post behind it so it can be updated when the page changes and withdrawn when it goes away.

= Something from my footer still shows up in answers =

Use *Train content → Check one page* to see what that page actually contributes. If a repeated block survives, it is usually because removing it would have left the page with too little text -- the safeguard that keeps a short page from being emptied. Wrapping it in `[bluebranch_chatbot_exclude]` removes it for certain.

= Can I restyle the widget? =

Every colour and size is a CSS custom property -- on `.chatbot-widget` for the chat button, and on `.chatbot-ask-container` / `.chatbot-generate-search-container` for the ask field and the search answer -- so your own stylesheet can rebrand them without touching the plugin. The answer styling inherits its colour from the theme and uses translucent neutrals, so it reads correctly on light and dark alike. To drop the plugin stylesheet entirely, switch off the default styling under *Settings*; that applies to all three modules. The three templates can also be overridden by copying them from `templates/` into a `bluebranch-chatbot/` folder in your theme.

= The answer arrives in one lump instead of word by word. =

Something between PHP and the browser is buffering the response. Behind nginx, switch buffering off for these routes (`proxy_buffering off`). The plugin already sends `X-Accel-Buffering: no` and disables PHP's own buffering. If the server has no cURL support, the plugin falls back to fetching the whole answer at once; the chat works, only the typing effect is missing.

= Does uninstalling delete my knowledge base? =

No. Deleting the plugin removes its settings and post meta from WordPress, but the trained content belongs to your account at the API and stays there. Use *Delete everything* on the trained content screen first if you want it gone.

== Screenshots ==

1. The chat widget on the front end.
2. The settings screen.
3. The trained content screen with the built-in test field.
4. Training existing content in batches.

== Changelog ==

= 1.0.0 =
* First release for WordPress, ported from the BlueBranch Chatbot extension for Contao.
* Chat widget, ask field and search answer as blocks and as shortcodes.
* Content is crawled over HTTP, bounded by the XML sitemap, falling back to rendered content.
* Headers, footers, menus and sidebars are marked by the site itself and removed.
* Repeated blocks such as cookie notices and newsletter boxes are detected and left out.
* A hash per post means a repeat run only sends what changed.
* Training on publish and update through WordPress cron, plus a batch run for existing content.
* Trained content screen with tier display, single and bulk deletion and a test field.
* Exclusion per post, inherited down a page branch.
* Automatic clean-up of content that is no longer public, on a configurable interval.
* Respects password protection and the noindex flags of Yoast SEO, Rank Math and SEOPress.

== Upgrade Notice ==

= 1.0.0 =
First release.
