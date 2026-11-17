import { translate as nextcloudTranslate, getLanguage } from '@nextcloud/l10n'
import englishCatalog from '../../l10n/en.json' with { type: 'json' }

const english = englishCatalog.translations

/**
 * Translate through Nextcloud, using English instead of Italian source text
 * when the current locale has no translation for a DocuSeal string.
 */
export function translate(app, text, ...args) {
	const translated = nextcloudTranslate(app, text, ...args)
	const language = (getLanguage() || 'en').split('-')[0].split('_')[0]

	if (
		app === 'docuseal_nc_app'
		&& language !== 'it'
		&& translated === text
		&& Object.hasOwn(english, text)
		&& english[text] !== text
	) {
		return nextcloudTranslate(app, english[text], ...args)
	}

	return translated
}
