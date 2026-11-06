/** The RadiusOne user guide lives on pingomni.com, for the latest release; the portal links there instead of carrying a copy. */
export const DOCS_URL = 'https://pingomni.com/docs/radiusone/';

/** A link to one part of the guide, e.g. docsUrl('telegram'). */
export const docsUrl = (anchor = ''): string => DOCS_URL + (anchor ? `#${anchor}` : '');
