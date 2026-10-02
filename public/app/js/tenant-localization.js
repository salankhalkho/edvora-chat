/**
 * EDVORA TENANT LOCALIZATION ENGINE
 * 
 * Handles multi-tenant timezone, country, and locale resolution.
 * Strictly adheres to Fallback 3: Default to U.S.A. (America/New_York / EDT / en-US).
 * Governed by architecture_timezone.md.
 */
(function() {
    'use strict';

    // 1. Initial optimistic hydration from localStorage with Fallback 3 (U.S.A.) defaults
    try {
        window.tenantCountry = localStorage.getItem('edvora_tenant_country') || 'United States';
        window.tenantTimezone = localStorage.getItem('edvora_tenant_tz') || 'America/New_York';
        window.tenantTzShort = localStorage.getItem('edvora_tenant_tz_short') || 'EDT';
        window.tenantLocale = 'en-US';
        window.tenantLocalization = {
            country: window.tenantCountry,
            timezone: window.tenantTimezone,
            timezone_short: window.tenantTzShort,
            locale: window.tenantLocale
        };
    } catch (_) {
        window.tenantCountry = 'United States';
        window.tenantTimezone = 'America/New_York';
        window.tenantTzShort = 'EDT';
        window.tenantLocale = 'en-US';
        window.tenantLocalization = {
            country: 'United States',
            timezone: 'America/New_York',
            timezone_short: 'EDT',
            locale: 'en-US'
        };
    }

    /**
     * Resolves timezone, short abbreviation, and locale from campus country and state
     */
    function resolveClientTimezone(country, state) {
        const cleanCountry = (country || '').trim().toLowerCase();
        const cleanState = (state || '').trim().toLowerCase();
        let timezone = 'America/New_York';
        let tzShort = 'EDT';
        let locale = 'en-US';

        if (cleanCountry === 'india' || cleanCountry === 'in' || cleanCountry === 'bharat') {
            timezone = 'Asia/Kolkata';
            tzShort = 'IST';
            locale = 'en-IN';
        } else if (cleanCountry === 'united states' || cleanCountry === 'usa' || cleanCountry === 'us' || cleanCountry === 'united states of america') {
            locale = 'en-US';
            const centralStates = ['al', 'alabama', 'tx', 'texas', 'il', 'illinois', 'tn', 'tennessee', 'mo', 'missouri', 'wi', 'wisconsin', 'mn', 'minnesota', 'la', 'louisiana', 'ms', 'mississippi', 'ok', 'oklahoma', 'ks', 'kansas', 'ne', 'nebraska', 'ia', 'iowa', 'ar', 'arkansas', 'nd', 'north dakota', 'sd', 'south dakota'];
            const mountainStates = ['co', 'colorado', 'ut', 'utah', 'nm', 'new mexico', 'id', 'idaho', 'mt', 'montana', 'wy', 'wyoming', 'az', 'arizona'];
            const pacificStates = ['ca', 'california', 'wa', 'washington', 'or', 'oregon', 'nv', 'nevada'];

            if (centralStates.includes(cleanState)) {
                timezone = 'America/Chicago';
                tzShort = 'CDT';
            } else if (mountainStates.includes(cleanState)) {
                timezone = (cleanState === 'az' || cleanState === 'arizona') ? 'America/Phoenix' : 'America/Denver';
                tzShort = 'MDT';
            } else if (pacificStates.includes(cleanState)) {
                timezone = 'America/Los_Angeles';
                tzShort = 'PDT';
            } else if (cleanState === 'ak' || cleanState === 'alaska') {
                timezone = 'America/Anchorage';
                tzShort = 'AKDT';
            } else if (cleanState === 'hi' || cleanState === 'hawaii') {
                timezone = 'Pacific/Honolulu';
                tzShort = 'HST';
            } else {
                timezone = 'America/New_York';
                tzShort = 'EDT';
            }
        } else if (cleanCountry === 'united kingdom' || cleanCountry === 'uk' || cleanCountry === 'gb' || cleanCountry === 'great britain') {
            timezone = 'Europe/London';
            tzShort = 'BST';
            locale = 'en-GB';
        } else if (cleanCountry === 'canada' || cleanCountry === 'ca') {
            timezone = (cleanState === 'bc' || cleanState === 'british columbia') ? 'America/Vancouver' : 'America/Toronto';
            tzShort = (cleanState === 'bc' || cleanState === 'british columbia') ? 'PDT' : 'EDT';
            locale = 'en-CA';
        } else if (cleanCountry === 'australia' || cleanCountry === 'au') {
            timezone = (cleanState === 'wa' || cleanState === 'perth') ? 'Australia/Perth' : 'Australia/Sydney';
            tzShort = (cleanState === 'wa' || cleanState === 'perth') ? 'AWST' : 'AEST';
            locale = 'en-AU';
        } else if (cleanCountry === 'united arab emirates' || cleanCountry === 'uae' || cleanCountry === 'dubai') {
            timezone = 'Asia/Dubai';
            tzShort = 'GST';
            locale = 'en-AE';
        } else if (cleanCountry === 'singapore' || cleanCountry === 'sg') {
            timezone = 'Asia/Singapore';
            tzShort = 'SGT';
            locale = 'en-SG';
        } else if (cleanCountry === 'germany' || cleanCountry === 'france' || cleanCountry === 'europe') {
            timezone = 'Europe/Berlin';
            tzShort = 'CEST';
            locale = 'en-DE';
        } else if (cleanCountry === 'ireland') {
            timezone = 'Europe/Dublin';
            tzShort = 'IST';
            locale = 'en-IE';
        } else if (cleanCountry === 'japan') {
            timezone = 'Asia/Tokyo';
            tzShort = 'JST';
            locale = 'ja-JP';
        } else if (cleanCountry === 'south africa') {
            timezone = 'Africa/Johannesburg';
            tzShort = 'SAST';
            locale = 'en-ZA';
        } else {
            timezone = 'America/New_York';
            tzShort = 'EDT';
            locale = 'en-US';
        }
        return { timezone, tzShort, locale };
    }

    /**
     * Updates tenant localization state and re-renders badges across the app
     */
    function updateTenantLocalization(orgData) {
        orgData = orgData || window.currentOrgProfile || {};
        const primaryCamp = orgData.primary_campus || {};
        const country = primaryCamp.country || orgData.country || 'United States';
        const state = primaryCamp.state || orgData.state || '';

        const resolved = resolveClientTimezone(country, state);
        window.tenantCountry = primaryCamp.country || orgData.country || 'United States';
        window.tenantTimezone = primaryCamp.timezone || orgData.timezone || resolved.timezone;
        window.tenantTzShort = primaryCamp.timezone_short || orgData.timezone_short || resolved.tzShort;
        window.tenantLocale = primaryCamp.locale || orgData.locale || resolved.locale;
        window.tenantLocalization = {
            country: window.tenantCountry,
            timezone: window.tenantTimezone,
            timezone_short: window.tenantTzShort,
            locale: window.tenantLocale
        };

        try {
            localStorage.setItem('edvora_tenant_country', window.tenantCountry);
            localStorage.setItem('edvora_tenant_tz', window.tenantTimezone);
            localStorage.setItem('edvora_tenant_tz_short', window.tenantTzShort);
        } catch(_) {}

        updateTenantTzLabels();
    }

    /**
     * Updates all timezone badges and column headers in loaded tabs
     */
    function updateTenantTzLabels() {
        const tz = window.tenantTzShort || 'EDT';

        const leadsHdr = document.getElementById('leadsColHeaderDateTime');
        if (leadsHdr) leadsHdr.innerHTML = 'Date &amp; Time (' + tz + ')';

        const leadsRangeLabel = document.getElementById('leadsRangeTzLabel');
        if (leadsRangeLabel) leadsRangeLabel.innerText = tz;

        const leadsExportBtn = document.getElementById('leadsExportCsvBtn');
        if (leadsExportBtn) leadsExportBtn.title = 'Export student contacts as CSV (' + tz + ')';

        const tourSchedHdr = document.getElementById('tourSchedColHeaderDateTime');
        if (tourSchedHdr) tourSchedHdr.innerHTML = 'Date &amp; Time (' + tz + ')';

        const tourHdr = document.getElementById('tourColHeaderBookedOn');
        if (tourHdr) tourHdr.innerHTML = 'Booked On (' + tz + ')';
        const tourExport = document.getElementById('exportCampusToursBtn');
        if (tourExport) tourExport.innerHTML = '📥 Export CSV (' + tz + ')';

        const cbHdr = document.getElementById('cbColHeaderRequested');
        if (cbHdr) cbHdr.innerHTML = 'Requested (' + tz + ')';
        const cbExport = document.getElementById('exportCallbacksBtn');
        if (cbExport) cbExport.innerHTML = '📥 Export CSV (' + tz + ')';

        const ovRange = document.getElementById('ovRangeTzLabel');
        if (ovRange) ovRange.innerText = tz;

        document.querySelectorAll('.tenant-tz-label').forEach(el => {
            el.innerText = tz;
        });
    }

    /**
     * Formats an ISO or UTC date string into the tenant's localized date and time
     */
    function formatTenantDateTime(dateInput, options) {
        if (!dateInput) return 'N/A';
        try {
            let dateObj;
            if (typeof dateInput === 'string' && !dateInput.endsWith('Z') && !dateInput.includes('T')) {
                dateObj = new Date(dateInput.replace(/-/g, '/') + ' UTC');
            } else {
                dateObj = new Date(dateInput);
            }
            if (isNaN(dateObj.getTime())) return dateInput;

            const tz = window.tenantTimezone || 'America/New_York';
            const tzShort = window.tenantTzShort || 'EDT';
            const locale = window.tenantLocale || 'en-US';

            const formatted = dateObj.toLocaleString(locale, {
                timeZone: tz,
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });
            return (options && options.omitTz) ? formatted : (formatted + ' ' + tzShort);
        } catch (e) {
            return dateInput;
        }
    }

    // Expose on window
    window.resolveClientTimezone = resolveClientTimezone;
    window.updateTenantLocalization = updateTenantLocalization;
    window.updateTenantTzLabels = updateTenantTzLabels;
    window.formatTenantDateTime = formatTenantDateTime;
    window.formatToIST = formatTenantDateTime; // Backward compatibility alias
})();
