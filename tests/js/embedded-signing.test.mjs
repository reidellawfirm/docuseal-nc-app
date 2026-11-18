import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'

const source = readFileSync(new URL('../../src/components/DocuSealModal.vue', import.meta.url), 'utf8')

test('embedded signing uses DocuSeal’s supported form component, not a blocked iframe', () => {
	assert.match(source, /<DocusealForm[\s\S]*:src="embedUrl"/)
	assert.doesNotMatch(source, /<iframe\s+:src="embedUrl"/)
	assert.match(source, /import \{ DocusealBuilder, DocusealForm \} from '@docuseal\/vue'/)
})

test('the signing component receives the configured self-hosted host on every load', () => {
	assert.match(source, /:host="builderHost"/)
	assert.match(source, /response\.data\.embedSrc && response\.data\.serverUrl/)
	assert.match(source, /this\.builderHost = response\.data\.serverUrl\.replace\(\/\^https\?:\\\/\\\/\/, ''\)/)
	assert.match(source, /@error="onSigningError"/)
})
