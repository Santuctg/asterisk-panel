/* Asterisk Panel UI language switcher (English / Bangla). */
(function () {
    'use strict';

    var bn = {
        'Dashboard': 'ড্যাশবোর্ড',
        'Web Phone': 'ওয়েব ফোন',
        'Extensions': 'এক্সটেনশন',
        'Voicemail': 'ভয়েসমেইল',
        'Devices': 'ডিভাইস',
        'Ring Groups': 'রিং গ্রুপ',
        'Call Parking': 'কল পার্কিং',
        'IVR Menus': 'আইভিআর মেনু',
        'Call Routing': 'কল রাউটিং',
        'Audio': 'অডিও',
        'Call Center': 'কল সেন্টার',
        'Conferences': 'কনফারেন্স',
        'Call History': 'কল ইতিহাস',
        'Tenants': 'টেন্যান্ট',
        'Users': 'ব্যবহারকারী',
        'Roles': 'রোল',
        'Tenant Security': 'টেন্যান্ট নিরাপত্তা',
        'Global Admins': 'গ্লোবাল অ্যাডমিন',
        'System': 'সিস্টেম',
        'Status': 'স্ট্যাটাস',
        'Settings': 'সেটিংস',
        'SSL / HTTPS': 'SSL / HTTPS',
        'Audit Log': 'অডিট লগ',
        'System Update': 'সিস্টেম আপডেট',
        'My Profile': 'আমার প্রোফাইল',
        'Sign out': 'সাইন আউট',
        'Sign in': 'সাইন ইন',
        'Sign in to manage your phone system.': 'আপনার ফোন সিস্টেম পরিচালনা করতে সাইন ইন করুন।',
        'Username': 'ইউজারনেম',
        'Username or email': 'ইউজারনেম বা ইমেইল',
        'Password': 'পাসওয়ার্ড',
        'Remember me': 'আমাকে মনে রাখুন',
        'Forgot your password? Contact your system administrator.': 'পাসওয়ার্ড ভুলে গেছেন? সিস্টেম অ্যাডমিনের সঙ্গে যোগাযোগ করুন।',
        'Those credentials do not match our records.': 'ইউজারনেম বা পাসওয়ার্ড সঠিক নয়।',
        'Language': 'ভাষা',
        'Search': 'খুঁজুন',
        'Search extensions, devices, queues…': 'এক্সটেনশন, ডিভাইস, কিউ খুঁজুন…',
        'Switch tenant': 'টেন্যান্ট পরিবর্তন করুন',
        'System status': 'সিস্টেম স্ট্যাটাস',
        'Switch color mode': 'রঙের মোড পরিবর্তন করুন',
        'Use light mode': 'লাইট মোড ব্যবহার করুন',
        'Use dark mode': 'ডার্ক মোড ব্যবহার করুন',
        'Toggle navigation': 'নেভিগেশন খুলুন বা বন্ধ করুন',
        'Dismiss': 'বন্ধ করুন',

        'System overview and live status': 'সিস্টেমের সারসংক্ষেপ ও লাইভ অবস্থা',
        'System Status': 'সিস্টেম স্ট্যাটাস',
        'Main Updates': 'প্রধান আপডেট',
        'Live Listen': 'লাইভ শুনুন',
        'New extension': 'নতুন এক্সটেনশন',
        'Active calls': 'সক্রিয় কল',
        'Registered phones': 'রেজিস্টার্ড ফোন',
        'Ring groups': 'রিং গ্রুপ',
        'IVR menus': 'আইভিআর মেনু',
        'Call queues': 'কল কিউ',
        'Recent calls': 'সাম্প্রতিক কল',
        'Ringing': 'রিং হচ্ছে',
        'Connected': 'সংযুক্ত',
        'No connection': 'সংযোগ নেই',
        'Asterisk is currently unreachable.': 'Asterisk বর্তমানে সংযোগযোগ্য নয়।',
        'Calls in progress will appear here automatically.': 'চলমান কল এখানে স্বয়ংক্রিয়ভাবে দেখা যাবে।',
        'No call history yet': 'এখনও কোনো কল ইতিহাস নেই',
        'Completed calls will appear here automatically.': 'সম্পন্ন কল এখানে স্বয়ংক্রিয়ভাবে দেখা যাবে।',
        'Back to panel': 'প্যানেলে ফিরুন',
        'Update progress': 'আপডেটের অগ্রগতি',
        'Starting update…': 'আপডেট শুরু হচ্ছে…',
        'Installing verified release…': 'যাচাইকৃত রিলিজ ইনস্টল হচ্ছে…',
        'The server is restarting. This page will reconnect automatically…': 'সার্ভার পুনরায় চালু হচ্ছে। এই পেজ স্বয়ংক্রিয়ভাবে পুনঃসংযোগ করবে…',
        'Quick actions': 'দ্রুত কাজ',
        'View all': 'সব দেখুন',
        'Live': 'লাইভ',
        'Offline': 'অফলাইন',
        'No connection': 'সংযোগ নেই',
        'No active calls': 'কোনো সক্রিয় কল নেই',
        'No call history yet': 'এখনও কোনো কল ইতিহাস নেই',
        'Asterisk is currently unreachable.': 'বর্তমানে Asterisk-এর সঙ্গে সংযোগ করা যাচ্ছে না।',
        'Connect to Asterisk to see live calls.': 'লাইভ কল দেখতে Asterisk-এর সঙ্গে সংযোগ করুন।',
        'Calls in progress will appear here in real time.': 'চলমান কল এখানে রিয়েল টাইমে দেখা যাবে।',
        'Once calls are made and CDR collection is configured, they will show up here.': 'কল হওয়ার পর এবং CDR collection চালু থাকলে সেগুলো এখানে দেখা যাবে।',
        'Add extension': 'এক্সটেনশন যোগ করুন',
        'Add device': 'ডিভাইস যোগ করুন',
        'Add voicemail box': 'ভয়েসমেইল বক্স যোগ করুন',
        'New ring group': 'নতুন রিং গ্রুপ',
        'New IVR menu': 'নতুন আইভিআর মেনু',
        'Conference rooms': 'কনফারেন্স রুম',
        'Apply config to Asterisk': 'Asterisk-এ কনফিগ প্রয়োগ করুন',

        'Add': 'যোগ করুন',
        'Create': 'তৈরি করুন',
        'Edit': 'সম্পাদনা',
        'Save': 'সংরক্ষণ করুন',
        'Save changes': 'পরিবর্তন সংরক্ষণ করুন',
        'Cancel': 'বাতিল',
        'Delete': 'মুছুন',
        'Remove': 'সরান',
        'Close': 'বন্ধ করুন',
        'Actions': 'কাজ',
        'Options': 'অপশন',
        'Enabled': 'সক্রিয়',
        'Disabled': 'নিষ্ক্রিয়',
        'Active': 'সক্রিয়',
        'Inactive': 'নিষ্ক্রিয়',
        'Available': 'উপলভ্য',
        'Unavailable': 'অনুপলভ্য',
        'Unknown': 'অজানা',
        'Name': 'নাম',
        'Description': 'বিবরণ',
        'Type': 'ধরন',
        'Number': 'নম্বর',
        'Extension': 'এক্সটেনশন',
        'Destination': 'গন্তব্য',
        'Priority': 'অগ্রাধিকার',
        'Date': 'তারিখ',
        'Time': 'সময়',
        'Duration': 'সময়কাল',
        'From': 'থেকে',
        'To': 'প্রতি',
        'Result': 'ফলাফল',
        'Caller': 'কলার',
        'Channel': 'চ্যানেল',
        'Role': 'রোল',
        'Email': 'ইমেইল',
        'Domain': 'ডোমেইন',
        'IP address': 'আইপি ঠিকানা',
        'Reason': 'কারণ',
        'Details': 'বিস্তারিত',
        'Created': 'তৈরির সময়',
        'Updated': 'আপডেটের সময়',
        'Yes': 'হ্যাঁ',
        'No': 'না',
        'All': 'সব',
        'None': 'কোনোটিই নয়',
        'Choose…': 'বেছে নিন…',
        'Choose destination': 'গন্তব্য বেছে নিন',
        'Back': 'ফিরে যান',
        'Next': 'পরবর্তী',
        'Previous': 'আগের',
        'Refresh': 'রিফ্রেশ',
        'Download': 'ডাউনলোড',
        'Play': 'চালান',
        'Stop': 'থামান',
        'Connect': 'সংযোগ করুন',
        'Call': 'কল করুন',
        'Hang up': 'কল কাটুন',
        'Show': 'দেখান',
        'Show field instructions': 'ফিল্ডের নির্দেশনা দেখুন',
        'Hide': 'লুকান',
        'Copied': 'কপি হয়েছে',

        'Outbound routes': 'আউটবাউন্ড রুট',
        'Inbound routes': 'ইনবাউন্ড রুট',
        'SIP trunks': 'SIP ট্রাঙ্ক',
        'Trunks': 'ট্রাঙ্কসমূহ',
        'Time conditions': 'টাইম কন্ডিশন',
        'Where calls come in, where they go out, and the trunks in between': 'কল কোথা থেকে আসে, কোথায় যায় এবং মাঝের ট্রাঙ্কসমূহ',
        'No outbound routes': 'কোনো আউটবাউন্ড রুট নেই',
        'Define how dialed numbers are matched and which trunk carries them.': 'ডায়াল করা নম্বর কীভাবে মিলবে এবং কোন ট্রাঙ্কে যাবে তা নির্ধারণ করুন।',
        'Add outbound route': 'আউটবাউন্ড রুট যোগ করুন',
        'Edit outbound route': 'আউটবাউন্ড রুট সম্পাদনা',
        'Route name': 'রুটের নাম',
        'Dial prefix (optional)': 'ডায়াল প্রিফিক্স (ঐচ্ছিক)',
        'Allowed calling times': 'অনুমোদিত কলের সময়',
        'Add schedule': 'সময়সূচি যোগ করুন',
        'Always allowed — no schedule restrictions.': 'সবসময় অনুমোদিত — কোনো সময়সূচির সীমা নেই।',
        'Week': 'সপ্তাহ',
        'Sun': 'রবি',
        'Mon': 'সোম',
        'Tue': 'মঙ্গল',
        'Wed': 'বুধ',
        'Thu': 'বৃহস্পতি',
        'Fri': 'শুক্র',
        'Sat': 'শনি',
        'Custom time': 'কাস্টম সময়',
        'Select at least one day for every outbound schedule.': 'প্রতিটি আউটবাউন্ড সময়সূচির জন্য অন্তত একটি দিন নির্বাচন করুন।',
        'Call recording': 'কল রেকর্ডিং',
        'Trunk': 'ট্রাঙ্ক',
        'Prefix': 'প্রিফিক্স',
        'Match pattern': 'ম্যাচ প্যাটার্ন',
        'Caller ID override': 'কলার আইডি পরিবর্তন',

        'Make and receive calls from this browser using a tenant extension.': 'টেন্যান্ট এক্সটেনশন ব্যবহার করে ব্রাউজার থেকে কল করুন ও গ্রহণ করুন।',
        'Use extension': 'এক্সটেনশন ব্যবহার করুন',
        'SIP password': 'SIP পাসওয়ার্ড',
        'Number or extension': 'নম্বর বা এক্সটেনশন',
        'Extension SIP password': 'এক্সটেনশনের SIP পাসওয়ার্ড',

        'Profile': 'প্রোফাইল',
        'Account': 'অ্যাকাউন্ট',
        'Change password': 'পাসওয়ার্ড পরিবর্তন',
        'Current password': 'বর্তমান পাসওয়ার্ড',
        'New password': 'নতুন পাসওয়ার্ড',
        'Confirm password': 'পাসওয়ার্ড নিশ্চিত করুন',

        'Music on Hold': 'হোল্ড মিউজিক',
        'Playlists': 'প্লেলিস্ট',
        'Announcements': 'ঘোষণা',
        'New announcement': 'নতুন ঘোষণা',
        'Upload': 'আপলোড',
        'Track name': 'ট্র্যাকের নাম',
        'Prompt': 'প্রম্পট',
        'Default destination': 'ডিফল্ট গন্তব্য',
        'Default destination type': 'ডিফল্ট গন্তব্যের ধরন',

        'Apply configuration': 'কনফিগারেশন প্রয়োগ করুন',
        'Business details': 'ব্যবসার তথ্য',
        'Outbound caller ID': 'আউটবাউন্ড কলার আইডি',
        'Voicemail email': 'ভয়েসমেইল ইমেইল',
        'Automatic call recording': 'স্বয়ংক্রিয় কল রেকর্ডিং',
        'Connection': 'সংযোগ',
        'Timezone': 'টাইমজোন',
        'Recording mode': 'রেকর্ডিং মোড',
        'Start recording': 'রেকর্ডিং শুরু',
        'Update settings': 'সেটিংস আপডেট করুন',

        'Key mappings': 'কী ম্যাপিং',
        'Add key': 'কী যোগ করুন',
        'Menu active': 'মেনু সক্রিয়',
        'Create IVR menu': 'আইভিআর মেনু তৈরি করুন',
        'Members': 'সদস্য',
        'Group': 'গ্রুপ',
        'Ring strategy': 'রিং কৌশল',
        'Music on hold class': 'হোল্ড মিউজিক ক্লাস',
        'Mailbox': 'মেইলবক্স',
        'Email delivery': 'ইমেইল ডেলিভারি',
        'Device': 'ডিভাইস',
        'Identity': 'পরিচয়',
        'Security': 'নিরাপত্তা',
        'Behavior': 'আচরণ',
        'Permissions': 'অনুমতি',
        'Roles & Permissions': 'রোল ও অনুমতি',
        'Access roles': 'অ্যাক্সেস রোল',
        'New user': 'নতুন ব্যবহারকারী',
        'New tenant': 'নতুন টেন্যান্ট',
        'New device': 'নতুন ডিভাইস',
        'New conference': 'নতুন কনফারেন্স',
        'New queue': 'নতুন কিউ',
        'New parking lot': 'নতুন পার্কিং লট',
        'Add address': 'ঠিকানা যোগ করুন',
        'Tenant Login Security': 'টেন্যান্ট লগইন নিরাপত্তা',
        'Block IP for this tenant': 'এই টেন্যান্টের জন্য আইপি ব্লক করুন',
        'No IP addresses are blocked for this tenant.': 'এই টেন্যান্টে কোনো আইপি ঠিকানা ব্লক করা নেই।',
        'Blocked IP addresses': 'ব্লক করা আইপি ঠিকানা',
        'Whitelist': 'হোয়াইটলিস্ট',
        'Ban IP': 'আইপি ব্যান করুন',
        'Unblock': 'আনব্লক',
        'Verify DNS': 'DNS যাচাই করুন',
        'Installed': 'ইনস্টল করা',
        'Verified': 'যাচাইকৃত',
        'Something went wrong': 'কিছু সমস্যা হয়েছে',
        'An unexpected error occurred. The details have been logged.': 'একটি অপ্রত্যাশিত সমস্যা হয়েছে। বিস্তারিত লগে সংরক্ষিত হয়েছে।',
        'Back to dashboard': 'ড্যাশবোর্ডে ফিরুন'
    };

    var textOriginal = new WeakMap();
    var attrOriginal = new WeakMap();
    var language = 'en';
    try { language = localStorage.getItem('panel-language') === 'bn' ? 'bn' : 'en'; } catch (e) {}

    function translated(value) {
        return language === 'bn' && Object.prototype.hasOwnProperty.call(bn, value) ? bn[value] : value;
    }

    function translateTextNode(node) {
        if (!textOriginal.has(node)) textOriginal.set(node, node.nodeValue);
        var original = textOriginal.get(node);
        var trimmed = original.trim();
        if (!trimmed) return;
        var parent = node.parentElement;
        if (parent && parent.closest('script, style, code, pre, [data-no-translate]')) return;
        var output = original.replace(trimmed, translated(trimmed));
        if (node.nodeValue !== output) node.nodeValue = output;
    }

    function translateAttributes(element) {
        if (!attrOriginal.has(element)) attrOriginal.set(element, {});
        var originals = attrOriginal.get(element);
        ['placeholder', 'title', 'aria-label', 'data-confirm', 'data-confirm-btn'].forEach(function (name) {
            if (!element.hasAttribute(name) && originals[name] === undefined) return;
            if (originals[name] === undefined) originals[name] = element.getAttribute(name);
            var original = originals[name];
            if (original !== null) element.setAttribute(name, translated(original));
        });
        if (element.matches('input[type="submit"], input[type="button"]')) {
            if (originals.value === undefined) originals.value = element.value;
            element.value = translated(originals.value);
        }
    }

    function translateTree(root) {
        if (root.nodeType === Node.TEXT_NODE) {
            translateTextNode(root);
            return;
        }
        if (!(root instanceof Element) && root !== document) return;
        if (root instanceof Element) translateAttributes(root);
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
        var node;
        while ((node = walker.nextNode())) {
            if (node.nodeType === Node.TEXT_NODE) translateTextNode(node);
            else translateAttributes(node);
        }
    }

    function applyLanguage(next) {
        language = next === 'bn' ? 'bn' : 'en';
        document.documentElement.lang = language;
        try { localStorage.setItem('panel-language', language); } catch (e) {}
        document.querySelectorAll('#languageSelect').forEach(function (select) { select.value = language; });
        translateTree(document.body);
        document.dispatchEvent(new CustomEvent('panel:language', { detail: { language: language } }));
    }

    document.querySelectorAll('#languageSelect').forEach(function (select) {
        select.value = language;
        select.addEventListener('change', function () { applyLanguage(select.value); });
    });
    applyLanguage(language);

    var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(translateTree);
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });

    window.PanelI18n = {
        setLanguage: applyLanguage,
        getLanguage: function () { return language; },
        translate: translated
    };
})();
