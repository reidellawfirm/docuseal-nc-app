import { getLanguage } from '@nextcloud/l10n'

const DOCU_SEAL_LANGUAGES = ['en', 'es', 'it', 'pt', 'fr', 'de', 'nl']

/**
 * Map the Nextcloud user's language to a locale supported by DocuSeal's
 * embedded builder. Regional variants use their base language.
 */
export function getDocuSealLanguage(language = getLanguage() || 'en') {
	const baseLanguage = (language || getLanguage() || 'en').split('-')[0].split('_')[0].toLowerCase()
	return DOCU_SEAL_LANGUAGES.includes(baseLanguage) ? baseLanguage : 'en'
}
