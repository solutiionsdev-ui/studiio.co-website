/* STUDIIO.CO — homepage prototype (EN / AR) */
(() => {
  'use strict';

  const IMG = 'img/';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  /* ------------------------------------------------------------------
     Copy — every string the page shows, in English and Arabic
     ------------------------------------------------------------------ */
  const T = {
    en: {
      'skip': 'Skip to content',
      'nav.work': 'Work', 'nav.services': 'Services', 'nav.about': 'About', 'nav.thinking': 'Thinking',
      'nav.cta': 'Start a project', 'nav.menu': 'Menu', 'nav.close': 'Close',
      'header.note': 'Integrated agency — Beirut · Dubai · California',
      'hero.lede': 'We build brands people talk about, and the systems that keep them growing.',
      'hero.location': 'Beirut · Dubai · California',
      'hero.reel': 'Play reel',
      'about.label': '(00) Who we are',
      'about.title': 'Two <em>i’s</em>.<br>One vision.',
      'about.intro': 'STUDIIO is an integrated creative and communications agency connecting the Middle East and the US, with studios in Beirut, Dubai and California. The two i’s in our name stand for what we deliver on every brief: Ideas and Impact.',
      'about.ideasLabel': 'The first <span class="lc">i</span>',
      'about.ideas': 'Ideas',
      'about.ideasBody': 'Ideas are where brands get noticed: the stories, strategies, visuals and experiences that make people stop and pay attention.',
      'about.impactLabel': 'The second <span class="lc">i</span>',
      'about.impact': 'Impact',
      'about.impactBody': 'Impact is where brands win: the reputation, reach and measurable growth that turn attention into results.',
      'about.both': 'Most agencies are built for one or the other. STUDIIO was built for <em>both</em>.',
      'about.markets': 'We work in Arabic and English, across markets from the Gulf and the Levant to the US.',
      'about.cta': 'Work with us',
      'services.label': '(01) What we do',
      'services.title': 'Five connected<br>disciplines.',
      'services.aside': 'One team handles strategy, story, media and production, so our clients get one partner, one plan and one consistent voice.',
      'work.label': '(02) Selected work',
      'work.title': 'Work that gets noticed.',
      'work.all': 'View all work',
      'work.empty': 'Case studies for this discipline are being prepared. Ask us for the full credentials deck.',
      'work.emptyCta': 'Request the deck',
      'filter.all': 'All',
      'cat.pr': 'PR & Communications', 'cat.production': 'Branding & Production', 'cat.digital': 'Digital & Growth',
      'cat.web': 'Websites', 'cat.events': 'Events',
      'proof.label': '(03) In numbers',
      'num.years': 'Years active', 'num.projects': 'Projects completed', 'num.brands': 'Brands served', 'num.cities': 'Studios — Beirut, Dubai & California',
      'clients.label': 'Brands we’ve worked with',
      'ai.label': '(04) Powered by AI',
      'ai.body': 'studiio.ai is our in-house AI content studio. Campaign visuals, product imagery and short-form video, produced at the speed social media demands and art-directed by the same team.',
      'ai.cta': 'See the AI portfolio on studiio.ai',
      'thinking.label': '(05) Thinking',
      'thinking.title': 'Notes from the studio.',
      'thinking.all': 'All articles',
      'read': 'min read',
      'contact.label': '(06) Start a project',
      'contact.title': 'Let’s make <em>noise.</em>',
      'contact.body': 'Tell us what you’re building, launching or fixing. We’ll come back with a plan.',
      'contact.email': 'Email', 'contact.phone': 'Phone', 'contact.offices': 'Offices', 'contact.social': 'Instagram',
      'form.name': 'Name', 'form.company': 'Company', 'form.email': 'Email', 'form.service': 'What do you need?',
      'form.choose': 'Choose a service', 'form.all': 'Not sure yet — all of it',
      'form.message': 'Tell us about the project', 'form.send': 'Send message',
      'form.note': 'Or email us directly at info@studiio.co',
      'form.sending': 'Sending…',
      'form.sent': 'Thank you — we’ve received your message and will be in touch soon.',
      'form.invalid': 'Please add your name and a valid email address.',
      'form.rate': 'You’ve sent several messages already. Please email us at info@studiio.co.',
      'form.fallback': 'We couldn’t send it from the site, so your email app has opened with your message. Just press send.',
      'form.failed': 'Something went wrong. Please email us at info@studiio.co.',
      'footer.rights': '© 2026 STUDIIO.CO. All rights reserved.',
      'footer.top': 'Back to top',
      'reel.close': 'Close',
      'lang.switch': 'عربي'
    },
    ar: {
      'skip': 'انتقل إلى المحتوى',
      'nav.work': 'أعمالنا', 'nav.services': 'خدماتنا', 'nav.about': 'من نحن', 'nav.thinking': 'أفكار',
      'nav.cta': 'ابدأ مشروعك', 'nav.menu': 'القائمة', 'nav.close': 'إغلاق',
      'header.note': 'وكالة متكاملة — بيروت · دبي · كاليفورنيا',
      'hero.lede': 'نبني علامات تجارية يتحدث عنها الناس، والأنظمة التي تُبقيها في نموّ مستمر.',
      'hero.location': 'بيروت · دبي · كاليفورنيا',
      'hero.reel': 'شاهد أعمالنا',
      'about.label': '(00) من نحن',
      'about.title': 'الفكرة <em>والأثر</em>.<br>رؤية واحدة.',
      'about.intro': 'STUDIIO وكالة متكاملة للإبداع والاتصال تربط الشرق الأوسط بالولايات المتحدة، ولدينا استوديوهات في بيروت ودبي وكاليفورنيا. حرفا الـ i في اسمنا يرمزان إلى ما نقدّمه في كل مشروع: الأفكار (Ideas) والأثر (Impact).',
      'about.ideasLabel': 'الـ <span class="lc">i</span> الأولى',
      'about.ideas': 'الأفكار',
      'about.ideasBody': 'الأفكار هي حيث تلفت العلامات الأنظار: القصص والاستراتيجيات والصور والتجارب التي تجعل الناس يتوقفون وينتبهون.',
      'about.impactLabel': 'الـ <span class="lc">i</span> الثانية',
      'about.impact': 'الأثر',
      'about.impactBody': 'الأثر هو حيث تنتصر العلامات: السمعة والانتشار والنمو القابل للقياس الذي يحوّل الانتباه إلى نتائج.',
      'about.both': 'معظم الوكالات بُنيت لأحدهما فقط. أما STUDIIO فبُنيت <em>لكليهما</em>.',
      'about.markets': 'نعمل بالعربية والإنجليزية، في أسواق تمتد من الخليج وبلاد الشام إلى الولايات المتحدة.',
      'about.cta': 'اعمل معنا',
      'services.label': '(01) ما نقدّمه',
      'services.title': 'خمسة تخصصات<br>مترابطة.',
      'services.aside': 'فريق واحد يتولى الاستراتيجية والقصة والإعلام والإنتاج، ليحصل عملاؤنا على شريك واحد وخطة واحدة وصوت واحد متناسق.',
      'work.label': '(02) مختارات من أعمالنا',
      'work.title': 'أعمال تلفت الأنظار.',
      'work.all': 'كل الأعمال',
      'work.empty': 'دراسات الحالة لهذا التخصص قيد الإعداد. اطلب منا الملف التعريفي الكامل.',
      'work.emptyCta': 'اطلب الملف التعريفي',
      'filter.all': 'الكل',
      'cat.pr': 'العلاقات العامة والاتصال', 'cat.production': 'الهوية والإنتاج', 'cat.digital': 'التسويق الرقمي والنمو',
      'cat.web': 'المواقع الإلكترونية', 'cat.events': 'الفعاليات',
      'proof.label': '(03) بالأرقام',
      'num.years': 'سنوات من العمل', 'num.projects': 'مشروع منجز', 'num.brands': 'علامة تجارية', 'num.cities': 'استوديوهات — بيروت ودبي وكاليفورنيا',
      'clients.label': 'علامات عملنا معها',
      'ai.label': '(04) مدعوم بالذكاء الاصطناعي',
      'ai.body': 'studiio.ai هو استوديو المحتوى بالذكاء الاصطناعي التابع لنا. صور الحملات والمنتجات والفيديوهات القصيرة، تُنتَج بالسرعة التي تتطلبها منصات التواصل وبإشراف فني من الفريق نفسه.',
      'ai.cta': 'شاهد أعمال الذكاء الاصطناعي على studiio.ai',
      'thinking.label': '(05) أفكار',
      'thinking.title': 'ملاحظات من الاستوديو.',
      'thinking.all': 'كل المقالات',
      'read': 'دقائق قراءة',
      'contact.label': '(06) ابدأ مشروعك',
      'contact.title': 'لنصنع <em>الحدث.</em>',
      'contact.body': 'أخبرنا بما تبنيه أو تطلقه أو تريد تطويره، وسنعود إليك بخطة.',
      'contact.email': 'البريد الإلكتروني', 'contact.phone': 'الهاتف', 'contact.offices': 'مكاتبنا', 'contact.social': 'إنستغرام',
      'form.name': 'الاسم', 'form.company': 'الشركة', 'form.email': 'البريد الإلكتروني', 'form.service': 'ما الذي تحتاجه؟',
      'form.choose': 'اختر خدمة', 'form.all': 'لست متأكداً بعد، كل ما سبق',
      'form.message': 'أخبرنا عن المشروع', 'form.send': 'أرسل الرسالة',
      'form.note': 'أو راسلنا مباشرة على info@studiio.co',
      'form.sending': 'جارٍ الإرسال…',
      'form.sent': 'شكراً لك، وصلتنا رسالتك وسنتواصل معك قريباً.',
      'form.invalid': 'يرجى إدخال اسمك وبريد إلكتروني صحيح.',
      'form.rate': 'لقد أرسلت عدة رسائل بالفعل. يرجى مراسلتنا على info@studiio.co.',
      'form.fallback': 'تعذّر الإرسال من الموقع، لذلك فتحنا تطبيق البريد برسالتك. اضغط إرسال فقط.',
      'form.failed': 'حدث خطأ ما. يرجى مراسلتنا على info@studiio.co.',
      'footer.rights': '© 2026 STUDIIO.CO. جميع الحقوق محفوظة.',
      'footer.top': 'العودة للأعلى',
      'reel.close': 'إغلاق',
      'lang.switch': 'English'
    }
  };

  /* ------------------------------------------------------------------
     Content data
     ------------------------------------------------------------------ */
  const SERVICES = [
    {
      id: 'digital', img: 'burger-cap.jpg',
      title: { en: 'Digital Marketing & Growth', ar: 'التسويق الرقمي والنمو' },
      lead: { en: 'Attention is good. Growth is better. We turn campaigns into results you can measure.', ar: 'الانتباه جيد، والنمو أفضل. نحوّل الحملات إلى نتائج يمكن قياسها.' },
      tags: {
        en: ['Digital strategy', 'Paid advertising', 'SEO', 'Performance marketing', 'Lead generation', 'Campaign optimization', 'Marketing automation', 'CRM solutions', 'AI-powered marketing'],
        ar: ['الاستراتيجية الرقمية', 'الإعلانات المدفوعة', 'تحسين محركات البحث', 'التسويق القائم على الأداء', 'توليد العملاء المحتملين', 'تحسين الحملات', 'أتمتة التسويق', 'حلول إدارة علاقات العملاء', 'التسويق بالذكاء الاصطناعي']
      }
    },
    {
      id: 'production', img: 'jewelry.jpg',
      title: { en: 'Branding & Creative Production', ar: 'الهوية والإنتاج الإبداعي' },
      lead: { en: 'Identities and content with a point of view — produced in-house, from concept to final frame.', ar: 'هويات ومحتوى بوجهة نظر واضحة، نُنتجها داخلياً من الفكرة حتى اللقطة الأخيرة.' },
      tags: {
        en: ['Brand strategy & positioning', 'Visual identity', 'Creative direction', 'Storytelling', 'Campaign development', 'Graphic design', 'Photography', 'Videography', 'Podcasts', 'Branded content'],
        ar: ['استراتيجية العلامة وتموضعها', 'الهوية البصرية', 'الإدارة الإبداعية', 'سرد القصص', 'تطوير الحملات', 'التصميم الجرافيكي', 'التصوير الفوتوغرافي', 'التصوير المرئي', 'البودكاست', 'المحتوى الترويجي للعلامة']
      }
    },
    {
      id: 'pr', img: 'chef.jpg',
      title: { en: 'Public Relations & Strategic Communications', ar: 'العلاقات العامة والاتصال الاستراتيجي' },
      lead: { en: 'We shape the story before it’s told — and make sure the right people tell it.', ar: 'نصوغ القصة قبل أن تُروى، ونضمن أن يرويها الأشخاص المناسبون.' },
      tags: {
        en: ['Media relations', 'Corporate communications', 'Reputation management', 'Executive profiling', 'Thought leadership', 'Press releases', 'Media planning & buying', 'Sponsorships', 'Strategic media partnerships'],
        ar: ['العلاقات الإعلامية', 'الاتصال المؤسسي', 'إدارة السمعة', 'بناء الحضور التنفيذي', 'القيادة الفكرية', 'البيانات الصحفية', 'تخطيط وشراء الوسائط الإعلامية', 'الرعايات', 'الشراكات الإعلامية الاستراتيجية']
      }
    },
    {
      id: 'web', img: 'cocktail.jpg', mock: 'browser',
      title: { en: 'Website Design & Development', ar: 'تصميم وتطوير المواقع الإلكترونية' },
      lead: { en: 'Fast, bilingual and built to convert — websites that work as hard as your team.', ar: 'مواقع سريعة وثنائية اللغة ومصمَّمة لتحويل الزوار إلى عملاء، تعمل بجدّ مثل فريقك.' },
      tags: {
        en: ['Corporate websites', 'E-commerce platforms', 'Landing pages', 'UX/UI design', 'Website redesign', 'Technical SEO', 'System integrations', 'Maintenance & hosting'],
        ar: ['المواقع المؤسسية', 'منصات التجارة الإلكترونية', 'صفحات الهبوط', 'تصميم تجربة وواجهة المستخدم', 'إعادة تصميم المواقع', 'تحسين محركات البحث التقني', 'تكامل الأنظمة', 'الصيانة والاستضافة']
      }
    },
    {
      id: 'events', img: 'tournament.jpg',
      title: { en: 'Events & Experiential Marketing', ar: 'الفعاليات وتسويق التجارب' },
      lead: { en: 'Moments people show up for, film and remember.', ar: 'لحظات يحضرها الناس، ويصوّرونها، ويتذكرونها.' },
      tags: {
        en: ['Corporate events', 'Product launches', 'Brand activations', 'Experiential campaigns', 'VIP experiences', 'Press events', 'Event planning & production'],
        ar: ['الفعاليات المؤسسية', 'إطلاق المنتجات', 'تفعيل العلامات التجارية', 'الحملات التفاعلية', 'تجارب كبار الشخصيات', 'الفعاليات الصحفية', 'تخطيط الفعاليات وإنتاجها']
      }
    }
  ];

  const SECTOR = {
    fnb: { en: 'F&B', ar: 'مطاعم ومشروبات' },
    sport: { en: 'Sport', ar: 'رياضة' },
    wellness: { en: 'Wellness', ar: 'عافية' },
    fashion: { en: 'Fashion', ar: 'أزياء' },
    jewelry: { en: 'Jewelry', ar: 'مجوهرات' },
    ai: { en: 'AI', ar: 'ذكاء اصطناعي' }
  };
  const CAT_SHORT = {
    pr: { en: 'PR', ar: 'علاقات عامة' },
    production: { en: 'Production', ar: 'إنتاج' },
    digital: { en: 'Digital', ar: 'رقمي' },
    web: { en: 'Web', ar: 'مواقع' },
    events: { en: 'Events', ar: 'فعاليات' }
  };

  // Real projects from the current studiio.co portfolio. Titles marked generic where the client is not named on the site.
  const WORK = [
    { img: 'jewelry.jpg', title: { en: 'Jewelry campaign', ar: 'حملة مجوهرات' }, sub: { en: 'Art direction & still life', ar: 'إدارة فنية وتصوير منتجات' }, cats: ['production'], sector: 'jewelry' },
    { img: 'steak.jpg', title: { en: 'Table du Chef Jean', ar: 'Table du Chef Jean' }, sub: { en: 'Restaurant campaign', ar: 'حملة مطعم' }, cats: ['production'], sector: 'fnb' },
    { img: 'tournament.jpg', title: { en: 'Rucker Park Tournament', ar: 'Rucker Park Tournament' }, sub: { en: 'Tournament coverage', ar: 'تغطية بطولة' }, cats: ['production'], sector: 'sport' },
    { img: 'cocktail.jpg', title: { en: 'MONS', ar: 'MONS' }, sub: { en: 'Cocktail menu', ar: 'قائمة الكوكتيلات' }, cats: ['production'], sector: 'fnb' },
    { img: 'recovery.jpg', title: { en: 'Recovery studio', ar: 'استوديو التعافي' }, sub: { en: 'Wellness campaign', ar: 'حملة عافية' }, cats: ['production'], sector: 'wellness' },
    { img: 'burgers.jpg', title: { en: 'Grandpas', ar: 'Grandpas' }, sub: { en: 'Social content', ar: 'محتوى لمنصات التواصل' }, cats: ['production', 'digital'], sector: 'fnb' },
    { img: 'ai-drinks.jpg', title: { en: 'AI product visuals', ar: 'صور منتجات بالذكاء الاصطناعي' }, sub: { en: 'Made with studiio.ai', ar: 'من إنتاج studiio.ai' }, cats: ['digital', 'production'], sector: 'ai' },
    { img: 'salad.jpg', title: { en: 'ILE', ar: 'ILE' }, sub: { en: 'Menu & lifestyle', ar: 'قائمة الطعام وأسلوب الحياة' }, cats: ['production'], sector: 'fnb' },
    { img: 'drape.jpg', title: { en: 'Basketball editorial', ar: 'جلسة تصوير كرة السلة' }, sub: { en: 'Sport fashion', ar: 'أزياء رياضية' }, cats: ['production'], sector: 'fashion' },
    { img: 'fitness.jpg', title: { en: 'Activewear campaign', ar: 'حملة ملابس رياضية' }, sub: { en: 'Sport & wellness', ar: 'رياضة وعافية' }, cats: ['production'], sector: 'wellness' }
  ];

  const STRIP = [
    { img: 'jewelry.jpg', cap: { en: 'Jewelry campaign', ar: 'حملة مجوهرات' } },
    { img: 'tournament.jpg', cap: { en: 'Rucker Park Tournament', ar: 'Rucker Park Tournament' } },
    { img: 'cocktail.jpg', cap: { en: 'MONS', ar: 'MONS' } },
    { img: 'fitness.jpg', cap: { en: 'Activewear campaign', ar: 'حملة ملابس رياضية' } },
    { img: 'chef.jpg', cap: { en: 'Table du Chef Jean', ar: 'Table du Chef Jean' } },
    { img: 'court-aerial.jpg', cap: { en: 'Rucker Park', ar: 'Rucker Park' } },
    { img: 'burger-cap.jpg', cap: { en: 'Grandpas', ar: 'Grandpas' } },
    { img: 'recovery.jpg', cap: { en: 'Wellness campaign', ar: 'حملة عافية' } },
    { img: 'dunk.jpg', cap: { en: 'Basketball editorial', ar: 'جلسة تصوير كرة السلة' } },
    { img: 'steak.jpg', cap: { en: 'Table du Chef Jean', ar: 'Table du Chef Jean' } },
    { img: 'cycling.jpg', cap: { en: 'Activewear campaign', ar: 'حملة ملابس رياضية' } },
    { img: 'ai-latte.jpg', cap: { en: 'studiio.ai', ar: 'studiio.ai' } },
    { img: 'dessert.jpg', cap: { en: 'ILE', ar: 'ILE' } },
    { img: 'reflekt.jpg', cap: { en: 'Reflekt', ar: 'Reflekt' } }
  ];

  // Bump when logo files change so browsers fetch the new ones.
  const LOGO_VERSION = 2;
  // Client logos (img/clients/) in their brand colours. Split into two sliding rows in this order.
  const CLIENT_LOGOS = [
    { name: 'Capelli Sport', file: 'capelli.png', w: 480, h: 174 },
    { name: 'Birdy Fam', file: 'birdy-fam.png', w: 408, h: 480, s: 1.15 },
    { name: 'Qube', file: 'qube.png', w: 480, h: 180 },
    { name: 'Karen Wazen Beach Club', file: 'karen-wazen.png', w: 220, h: 192, s: 1.25 },
    { name: 'BYD', file: 'byd.png', w: 248, h: 58 },
    { name: 'The Protein Bar', file: 'protein-bar.png', w: 480, h: 306 },
    { name: 'Reflekt', file: 'reflekt.png', w: 480, h: 246 },
    { name: 'Mōns', file: 'mons.png', w: 480, h: 132 },
    { name: 'Beirut Beer', file: 'beirut-beer.png', w: 246, h: 102 },
    { name: 'Luma', file: 'luma.png', w: 480, h: 140 },
    { name: 'The Lab', file: 'the-lab.png', w: 480, h: 330 },
    { name: 'Josée Efrem', file: 'josee-efrem.png', w: 480, h: 88 },
    { name: 'Caliber', file: 'caliber.png', w: 480, h: 95 },
    { name: 'Île Beach Bar', file: 'ile.png', w: 138, h: 108 },
    { name: 'Mikkai', file: 'mikkai.png', w: 480, h: 200 },
    { name: 'Step Ahead', file: 'step-ahead.png', w: 480, h: 107 },
    { name: 'Wimsi', file: 'wimsi.png', w: 480, h: 167 },
    { name: 'Pilatté Wellness', file: 'pilatte.png', w: 184, h: 84 },
    { name: 'Fine B', file: 'fine-b.png', w: 480, h: 302 },
    { name: 'Quwati', file: 'quwati.png', w: 480, h: 136 },
    { name: 'us Coffee', file: 'us-coffee.png', w: 210, h: 108 },
    { name: 'Stepahead Sports School', file: 'sss.png', w: 480, h: 438 },
    { name: 'UNCVRD', file: 'uncvrd.png', w: 480, h: 84 }
  ];

  const ARTICLES = [
    { img: 'portrait.jpg', cat: 'pr', mins: 5, title: { en: 'Why your launch needs a press strategy before a media budget', ar: 'لماذا يحتاج إطلاقك إلى استراتيجية إعلامية قبل ميزانية الإعلانات' } },
    { img: 'drape.jpg', cat: 'production', mins: 7, title: { en: 'Bilingual by design: building brands that speak Arabic and English', ar: 'ثنائية اللغة منذ البداية: بناء علامات تتحدث العربية والإنجليزية' } },
    { img: 'players.jpg', cat: 'events', mins: 4, title: { en: 'The event is the content: planning activations for the feed', ar: 'الفعالية هي المحتوى: كيف نخطط التفعيلات لمنصات التواصل' } }
  ];

  /* ------------------------------------------------------------------
     State
     ------------------------------------------------------------------ */
  let lang = 'en';
  let filter = 'all';
  const t = (k) => (T[lang] && T[lang][k]) ?? T.en[k] ?? k;
  const L = (o) => (o && (o[lang] ?? o.en)) ?? '';
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  try {
    const fromUrl = new URLSearchParams(location.search).get('lang');
    const stored = localStorage.getItem('studiio-lang');
    if (fromUrl === 'ar' || fromUrl === 'en') lang = fromUrl;
    else if (stored === 'ar' || stored === 'en') lang = stored;
  } catch (e) { /* storage unavailable */ }

  /* ------------------------------------------------------------------
     Renderers
     ------------------------------------------------------------------ */
  function applyStatic() {
    const html = document.documentElement;
    html.lang = lang;
    html.dir = lang === 'ar' ? 'rtl' : 'ltr';
    $$('[data-i18n]').forEach((el) => { el.textContent = t(el.dataset.i18n); });
    $$('[data-i18n-html]').forEach((el) => { el.innerHTML = t(el.dataset.i18nHtml); });
    $$('[data-lang-toggle]').forEach((btn) => {
      btn.textContent = t('lang.switch');
      btn.lang = lang === 'ar' ? 'en' : 'ar';
      btn.setAttribute('aria-label', lang === 'ar' ? 'Switch to English' : 'التبديل إلى العربية');
    });
    const menuBtn = $('.menu-btn');
    if (menuBtn) menuBtn.textContent = document.body.classList.contains('menu-open') ? t('nav.close') : t('nav.menu');
  }

  function renderStrip() {
    const track = $('#strip-track');
    const items = (hidden) => STRIP.map((s, i) => `
      <figure class="strip-item" data-reel-open="${i}" ${hidden ? 'aria-hidden="true"' : 'tabindex="0" role="button"'} aria-label="${esc(L(s.cap))}">
        <img src="${IMG + s.img}" alt="${hidden ? '' : esc(L(s.cap))}" width="1200" height="1500" ${i > 4 || hidden ? 'loading="lazy"' : ''} decoding="async">
        <figcaption class="label">${esc(L(s.cap))}</figcaption>
      </figure>`).join('');
    track.innerHTML = items(false) + items(true);
  }

  function renderServices() {
    $('#svc-list').innerHTML = SERVICES.map((s, i) => {
      const media = s.mock === 'browser'
        ? `<div class="svc-media browser"><div class="browser-bar"><i></i><i></i><i></i><span>studiio.co</span></div><div class="browser-page"><img src="${IMG + s.img}" alt="" loading="lazy"><b>STUDIIO.CO</b></div></div>`
        : `<div class="svc-media"><img src="${IMG + s.img}" alt="" loading="lazy"></div>`;
      return `
      <details class="svc" data-img="${IMG + s.img}">
        <summary>
          <span class="svc-num">0${i + 1}</span>
          <span class="svc-title">${esc(L(s.title))}</span>
          <span class="svc-icon" aria-hidden="true"></span>
        </summary>
        <div class="svc-body">
          <div>
            <p class="svc-lead">${esc(L(s.lead))}</p>
            <ul class="tags">${L(s.tags).map((tg) => `<li class="tag">${esc(tg)}</li>`).join('')}</ul>
          </div>
          ${media}
        </div>
      </details>`;
    }).join('');
  }

  function renderFilters() {
    const keys = ['all', 'digital', 'production', 'pr', 'web', 'events'];
    $('#filters').innerHTML = keys.map((k) =>
      `<button class="pill filter" type="button" data-filter="${k}" aria-pressed="${k === filter}">${esc(k === 'all' ? t('filter.all') : t('cat.' + k))}</button>`
    ).join('');
  }

  function renderWork() {
    const visible = WORK.filter((w) => filter === 'all' || w.cats.includes(filter));
    const grid = $('#work-grid');
    if (!visible.length) {
      grid.innerHTML = `<div class="empty"><p>${esc(t('work.empty'))}</p><a class="pill pill--solid" href="#contact">${esc(t('work.emptyCta'))}</a></div>`;
      return;
    }
    // Desktop rows alternate 2-up (wide) and 3-up; a short last row fills itself.
    const sizes = [];
    let i = 0, three = false;
    while (i < visible.length) {
      let n = three ? 3 : 2;
      n = Math.min(n, visible.length - i);
      const size = n === 3 ? 2 : 3;
      for (let k = 0; k < n; k++) sizes.push(size);
      i += n; three = !three;
    }
    grid.innerHTML = visible.map((w, idx) => {
      const wideMobile = idx % 3 === 0 || (idx === visible.length - 1 && idx % 3 === 1);
      return `
      <article class="tile${wideMobile ? ' is-wide' : ''}" data-size="${sizes[idx]}" style="animation-delay:${idx * 60}ms">
        <a href="#work" aria-label="${esc(L(w.title))} — ${esc(L(w.sub))}">
          <div class="tile-media"><img src="${IMG + w.img}" alt="${esc(L(w.title))}" loading="lazy" decoding="async" width="1200" height="1500"></div>
          <div class="tile-info">
            <div><h3 class="tile-title"${/^[A-Za-z]/.test(L(w.title)) && lang === 'ar' ? ' dir="ltr"' : ''}>${esc(L(w.title))}</h3><p class="tile-sub">${esc(L(w.sub))}</p></div>
            <div class="tile-tags label"><span>${esc(L(SECTOR[w.sector]))}</span><span>${w.cats.map((c) => esc(L(CAT_SHORT[c]))).join(' · ')}</span></div>
          </div>
        </a>
      </article>`;
    }).join('');
  }

  function renderClients() {
    // Equalise visual weight: wide wordmarks get shorter, compact marks taller.
    const logo = (c, dup) => {
      const h = Math.round(Math.max(26, Math.min(60, Math.sqrt(5200 / (c.w / c.h)))) * (c.s || 1));
      return `<span class="client-logo${dup ? ' dup' : ''}"><img src="${IMG}clients/${c.file}?v=${LOGO_VERSION}" alt="" width="${c.w}" height="${c.h}" style="--h:${h}" loading="lazy" decoding="async"></span>`;
    };
    const half = Math.ceil(CLIENT_LOGOS.length / 2);
    [CLIENT_LOGOS.slice(0, half), CLIENT_LOGOS.slice(half)].forEach((row, i) => {
      $(i ? '#logos-b' : '#logos-a').innerHTML =
        row.map((c) => logo(c, false)).join('') + row.map((c) => logo(c, true)).join('');
    });
    $('#clients-list').innerHTML = CLIENT_LOGOS.map((c) => `<li>${esc(c.name)}</li>`).join('');
  }

  function renderArticles() {
    $('#articles').innerHTML = ARTICLES.map((a) => `
      <a class="article reveal" href="#thinking">
        <div class="article-thumb"><img src="${IMG + a.img}" alt="" loading="lazy"></div>
        <div>
          <p class="article-meta label"><span class="cat">${esc(L(CAT_SHORT[a.cat]))}</span><span>${a.mins} ${esc(t('read'))}</span></p>
          <h3 class="article-title">${esc(L(a.title))}</h3>
        </div>
        <span class="article-go" aria-hidden="true"><span class="arrow">→</span></span>
      </a>`).join('');
  }

  function renderServiceSelect() {
    const sel = $('#service-select');
    const current = sel.value;
    sel.innerHTML = `<option value="" disabled ${current ? '' : 'selected'}>${esc(t('form.choose'))}</option>` +
      SERVICES.map((s) => `<option value="${s.id}">${esc(L(s.title))}</option>`).join('') +
      `<option value="all">${esc(t('form.all'))}</option>`;
    if (current) sel.value = current;
  }

  function renderAll() {
    applyStatic();
    renderStrip();
    renderServices();
    renderFilters();
    renderWork();
    renderClients();
    renderArticles();
    renderServiceSelect();
    observeReveals();
  }

  /* ------------------------------------------------------------------
     Logo — the official STUDIIO.CO wordmark redrawn as SVG paths
     (one path per letter so the hero can animate them in)
     ------------------------------------------------------------------ */
  const LOGO_GLYPHS = [
    'M10 0H94Q102 0 102 8V24H80V18H24V30H94Q102 30 102 38V68Q102 76 94 76H10Q2 76 2 68V52H24V58H80V46H10Q2 46 2 38V8Q2 0 10 0Z', // S
    'M108 2H210V18H170V74H148V18H108Z', // T
    'M217 2H239V58H292V2H314V68Q314 76 306 76H225Q217 76 217 68Z', // U
    'M327 2H420Q428 2 428 10V66Q428 74 420 74H327ZM349 20V56H405V20Z', // D
    'M439 2H461V74H439Z', // I
    'M474 2H496V74H474Z', // I
    'M515 0H600Q608 0 608 8V68Q608 76 600 76H515Q507 76 507 68V8Q507 0 515 0ZM529 18V58H586V18Z', // O
    'M617 56H638V74H617Z', // .
    'M654 0H738Q746 0 746 8V26H724V18H668V58H724V50H746V68Q746 76 738 76H654Q646 76 646 68V8Q646 0 654 0Z', // C
    'M764 0H848Q856 0 856 8V68Q856 76 848 76H764Q756 76 756 68V8Q756 0 764 0ZM778 18V58H834V18Z' // O
  ];
  const logoSVG = () =>
    `<svg viewBox="2 0 854 76" fill-rule="evenodd" aria-hidden="true" focusable="false">${
      LOGO_GLYPHS.map((d, i) => `<path class="g${i === 7 ? ' dot' : ''}" style="--i:${i}" d="${d}"/>`).join('')
    }</svg>`;

  /* ------------------------------------------------------------------
     Interactions
     ------------------------------------------------------------------ */
  // Header state
  const header = $('#site-header');
  const darkSections = $$('.services, .contact, .site-footer');
  const onScroll = () => {
    header.classList.toggle('is-scrolled', window.scrollY > 8);
    const probe = header.offsetHeight / 2;
    header.classList.toggle('on-dark', darkSections.some((s) => {
      const r = s.getBoundingClientRect();
      return r.top <= probe && r.bottom >= probe;
    }));
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(([entry]) => {
      header.classList.toggle('show-logo', !entry.isIntersecting);
    }, { rootMargin: '-60px 0px 0px 0px' }).observe($('#wordmark'));
  }

  // Scroll reveal
  let revealObserver = null;
  function observeReveals() {
    const els = $$('.reveal:not(.in)');
    if (!('IntersectionObserver' in window) || reducedMotion.matches) { els.forEach((el) => el.classList.add('in')); return; }
    if (!revealObserver) {
      revealObserver = new IntersectionObserver((entries) => {
        entries.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('in'); revealObserver.unobserve(e.target); } });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    }
    els.forEach((el) => revealObserver.observe(el));
  }

  // Language toggle
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-lang-toggle]');
    if (!btn) return;
    lang = lang === 'ar' ? 'en' : 'ar';
    try { localStorage.setItem('studiio-lang', lang); } catch (err) { /* ignore */ }
    try {
      const url = new URL(location.href);
      url.searchParams.set('lang', lang);
      history.replaceState(null, '', url);
    } catch (err) { /* ignore */ }
    closeMenu();
    renderAll();
  });

  // Mobile menu
  const menu = $('#mobile-menu');
  const menuBtn = $('.menu-btn');
  function openMenu() { menu.hidden = false; document.body.classList.add('menu-open'); menuBtn.setAttribute('aria-expanded', 'true'); menuBtn.textContent = t('nav.close'); }
  function closeMenu() { if (menu.hidden) return; menu.hidden = true; document.body.classList.remove('menu-open'); menuBtn.setAttribute('aria-expanded', 'false'); menuBtn.textContent = t('nav.menu'); }
  menuBtn.addEventListener('click', () => (menu.hidden ? openMenu() : closeMenu()));
  menu.addEventListener('click', (e) => { if (e.target.closest('a')) closeMenu(); });

  // Work filters
  $('#filters').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-filter]');
    if (!btn) return;
    filter = btn.dataset.filter;
    $$('[data-filter]').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
    renderWork();
  });

  // Cursor follower over the film strip
  const cursor = $('#cursor');
  const strip = $('#strip');
  let cx = -200, cy = -200, tx = -200, ty = -200, raf = null;
  function loop() {
    cx += (tx - cx) * 0.22; cy += (ty - cy) * 0.22;
    cursor.style.setProperty('--x', cx + 'px'); cursor.style.setProperty('--y', cy + 'px');
    raf = (Math.abs(tx - cx) > 0.3 || Math.abs(ty - cy) > 0.3) ? requestAnimationFrame(loop) : null;
  }
  strip.addEventListener('pointermove', (e) => {
    if (!finePointer.matches) return;
    tx = e.clientX; ty = e.clientY;
    if (!cursor.classList.contains('on')) { cx = tx; cy = ty; cursor.classList.add('on'); }
    if (!raf) raf = requestAnimationFrame(loop);
  });
  strip.addEventListener('pointerleave', () => cursor.classList.remove('on'));

  // Service hover preview
  const preview = $('#svc-preview');
  const previewImg = $('img', preview);
  const svcList = $('#svc-list');
  svcList.addEventListener('pointerover', (e) => {
    if (!finePointer.matches) return;
    const summary = e.target.closest('summary');
    if (!summary) return;
    const details = summary.parentElement;
    if (details.open) { preview.classList.remove('on'); return; }
    previewImg.src = details.dataset.img;
    preview.classList.add('on');
  });
  svcList.addEventListener('pointermove', (e) => {
    if (!finePointer.matches) return;
    preview.style.setProperty('--x', e.clientX + 'px');
    preview.style.setProperty('--y', e.clientY + 'px');
    if (!e.target.closest('summary')) preview.classList.remove('on');
  });
  svcList.addEventListener('pointerleave', () => preview.classList.remove('on'));
  window.addEventListener('scroll', () => preview.classList.remove('on'), { passive: true });
  svcList.addEventListener('toggle', (e) => {
    if (e.target.open) {
      preview.classList.remove('on');
      $$('.svc', svcList).forEach((d) => { if (d !== e.target) d.open = false; });
    }
  }, true);

  // Reel lightbox
  const reel = $('#reel');
  const reelImg = $('#reel-img');
  const reelCap = $('#reel-cap');
  const reelCount = $('#reel-count');
  const reelBar = $('#reel-bar');
  const DUR = 2600;
  let reelIdx = 0, reelTimer = null, lastFocus = null;
  const pad = (n) => String(n).padStart(2, '0');
  function showSlide(i) {
    reelIdx = (i + STRIP.length) % STRIP.length;
    const s = STRIP[reelIdx];
    reelImg.src = IMG + s.img;
    reelImg.alt = L(s.cap);
    reelImg.style.animation = 'none'; void reelImg.offsetWidth; reelImg.style.animation = '';
    reelCap.textContent = L(s.cap);
    reelCount.textContent = `${pad(reelIdx + 1)} / ${pad(STRIP.length)}`;
    reelBar.classList.remove('run'); void reelBar.offsetWidth;
    if (!reducedMotion.matches) {
      reelBar.style.setProperty('--dur', DUR + 'ms');
      reelBar.classList.add('run');
      clearTimeout(reelTimer);
      reelTimer = setTimeout(() => showSlide(reelIdx + 1), DUR);
    }
  }
  function openReel(i) {
    lastFocus = document.activeElement;
    reel.hidden = false;
    document.body.classList.add('reel-open');
    cursor.classList.remove('on');
    showSlide(i);
    $('#reel-close').focus();
  }
  function closeReel() {
    if (reel.hidden) return;
    reel.hidden = true;
    document.body.classList.remove('reel-open');
    clearTimeout(reelTimer);
    if (lastFocus) lastFocus.focus();
  }
  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-reel-open]');
    if (opener) openReel(Number(opener.dataset.reelOpen) || 0);
  });
  strip.addEventListener('keydown', (e) => {
    const item = e.target.closest('[data-reel-open]');
    if (item && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openReel(Number(item.dataset.reelOpen)); }
  });
  $('#reel-close').addEventListener('click', closeReel);
  $('#reel-next').addEventListener('click', () => showSlide(reelIdx + 1));
  $('#reel-prev').addEventListener('click', () => showSlide(reelIdx - 1));
  reel.addEventListener('click', (e) => { if (e.target === reel) closeReel(); });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { closeReel(); closeMenu(); }
    if (reel.hidden) return;
    const fwd = lang === 'ar' ? 'ArrowLeft' : 'ArrowRight';
    const back = lang === 'ar' ? 'ArrowRight' : 'ArrowLeft';
    if (e.key === fwd) showSlide(reelIdx + 1);
    if (e.key === back) showSlide(reelIdx - 1);
  });

  // Contact form → contact.php (PHP mail on Hostinger), with a mailto fallback so no enquiry is lost
  const form = $('#contact-form');
  const note = $('#form-note');
  const submitBtn = $('#form-submit');
  const setNote = (key, state) => {
    note.textContent = t(key);
    note.classList.toggle('sent', state === 'sent');
    note.classList.toggle('error', state === 'error');
  };
  const mailtoFallback = (data) => {
    const svc = SERVICES.find((s) => s.id === data.get('service'));
    const subject = `New enquiry — ${svc ? L(svc.title) : 'studiio.co'} — ${data.get('name')}`;
    const body = [
      `Name: ${data.get('name')}`,
      `Company: ${data.get('company') || '—'}`,
      `Email: ${data.get('email')}`,
      `Service: ${svc ? L(svc.title) : '—'}`,
      '',
      String(data.get('message') || '')
    ].join('\n');
    window.location.href = `mailto:info@studiio.co?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
  };
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const nameEl = form.elements.namedItem('name');
    const emailEl = form.elements.namedItem('email');
    const nameOk = nameEl.value.trim() !== '';
    const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(emailEl.value.trim());
    nameEl.setAttribute('aria-invalid', String(!nameOk));
    emailEl.setAttribute('aria-invalid', String(!emailOk));
    if (!nameOk || !emailOk) { setNote('form.invalid', 'error'); (nameOk ? emailEl : nameEl).focus(); return; }

    form.elements.namedItem('lang').value = lang;
    const data = new FormData(form);
    submitBtn.disabled = true;
    setNote('form.sending');
    try {
      const res = await fetch(form.action, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
      const json = await res.json().catch(() => null);
      if (json && json.ok) { form.reset(); renderServiceSelect(); setNote('form.sent', 'sent'); }
      else if (json && json.error === 'invalid') setNote('form.invalid', 'error');
      else if (json && json.error === 'rate') setNote('form.rate', 'error');
      else { mailtoFallback(data); setNote('form.fallback', 'error'); }
    } catch (err) {
      mailtoFallback(data);
      setNote('form.fallback', 'error');
    } finally {
      submitBtn.disabled = false;
    }
  });
  // Result of a no-JavaScript post (contact.php redirects back with ?sent=1 or ?sent=0)
  try {
    const sent = new URLSearchParams(location.search).get('sent');
    if (sent === '1') setNote('form.sent', 'sent');
    if (sent === '0') setNote('form.failed', 'error');
  } catch (err) { /* ignore */ }

  /* ------------------------------------------------------------------
     Boot
     ------------------------------------------------------------------ */
  $$('[data-logo]').forEach((el) => el.insertAdjacentHTML('beforeend', logoSVG()));
  renderAll();
})();
