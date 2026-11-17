import assert from 'node:assert/strict'
import test from 'node:test'
import { getDocuSealLanguage } from '../../src/utils/language.js'

test('uses the Nextcloud language and regional base language', () => {
	const cases = {
		en: 'en',
		'en-US': 'en',
		en_GB: 'en',
		it: 'it',
		'it-IT': 'it',
		de_DE: 'de',
		fr: 'fr',
		'fr-CA': 'fr',
		es_MX: 'es',
		'pt-BR': 'pt',
		nl_NL: 'nl',
		EN_us: 'en',
	}
	for (const [input, expected] of Object.entries(cases)) {
		assert.equal(getDocuSealLanguage(input), expected, input)
	}
})

test('unsupported builder languages fall back to English', () => {
	for (const language of ['ja', 'zh-CN', 'ru_RU', 'pl', '', null, undefined]) {
		assert.equal(getDocuSealLanguage(language), 'en', language)
	}
})

test('the Nextcloud runtime language is used by default', () => {
	assert.equal(getDocuSealLanguage(), 'en')
})

test('missing translations fall back to English, not Italian source text', async () => {
globalThis._nc_l10n_language = 'de'
const { translate } = await import('../../src/utils/translate.js')
const italianSource = 'Richiedi firma'
const english = 'Request signature'
assert.equal(translate('docuseal_nc_app', italianSource), english)
globalThis._nc_l10n_language = 'it'
assert.equal(translate('docuseal_nc_app', italianSource), italianSource)
globalThis._nc_l10n_language = 'en'
})
