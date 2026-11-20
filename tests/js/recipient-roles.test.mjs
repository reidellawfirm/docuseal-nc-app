import test from 'node:test'
import assert from 'node:assert/strict'
import {
	buildRecipientRoleAssignments,
	createDefaultParties,
	ordinalPartyName,
	recipientRoleKey,
} from '../../src/utils/recipientRoles.js'

const parties = [
	{ name: 'First Party', uuid: 'first' },
	{ name: 'Second Party', uuid: 'second' },
	{ name: 'Third Party', uuid: 'third' },
]
const email = (address) => ({
	type: 'email',
	id: address,
	email: address,
	displayName: address,
})
const user = (uid) => ({
	type: 'user',
	id: uid,
	email: uid,
	displayName: uid,
})

test('recipient keys distinguish Nextcloud users from external emails', () => {
	assert.equal(recipientRoleKey(user('alice')), 'user:alice')
	assert.equal(recipientRoleKey(email(' Bob@Example.COM ')), 'email:bob@example.com')
})

test('missing assignments use unique parties in order', () => {
	const result = buildRecipientRoleAssignments({
		recipients: [user('alice'), email('bob@example.com')],
		parties,
	})
	assert.equal(result.error, null)
	assert.deepEqual(result.recipientRoles, {
		'user:alice': 'First Party',
		'email:bob@example.com': 'Second Party',
	})
})

test('an explicit later assignment is reserved before automatic fallback', () => {
	const result = buildRecipientRoleAssignments({
		recipients: [email('alice@example.com'), email('bob@example.com')],
		parties,
		recipientRoles: { 'email:bob@example.com': 'second party' },
	})
	assert.equal(result.error, null)
	assert.deepEqual(result.recipientRoles, {
		'email:alice@example.com': 'First Party',
		'email:bob@example.com': 'Second Party',
	})
})

test('self-signing reuses an already selected current user without consuming two parties', () => {
	const result = buildRecipientRoleAssignments({
		recipients: [user('alice'), email('bob@example.com')],
		parties,
		currentUserId: 'alice',
		currentUserRole: 'Second Party',
		embedSign: true,
	})
	assert.equal(result.error, null)
	assert.equal(result.selfInRecipients, true)
	assert.equal(result.currentUserRole, 'Second Party')
	assert.deepEqual(result.recipientRoles, {
		'user:alice': 'Second Party',
		'email:bob@example.com': 'First Party',
	})
})

test('self-signing adds one party when the current user is not selected', () => {
	const result = buildRecipientRoleAssignments({
		recipients: [email('bob@example.com')],
		parties,
		currentUserId: 'alice',
		currentUserRole: 'Second Party',
		embedSign: true,
	})
	assert.equal(result.error, null)
	assert.equal(result.selfInRecipients, false)
	assert.equal(result.currentUserRole, 'Second Party')
	assert.deepEqual(result.recipientRoles, {
		'email:bob@example.com': 'First Party',
	})
})

test('duplicate recipient entries are rejected instead of silently creating duplicate signers', () => {
	const result = buildRecipientRoleAssignments({
		recipients: [email('bob@example.com'), email('BOB@example.com')],
		parties,
	})
	assert.equal(result.error, 'duplicate_recipient')
})

test('duplicate party assignments are rejected', () => {
	const result = buildRecipientRoleAssignments({
		recipients: [email('alice@example.com'), email('bob@example.com')],
		parties,
		recipientRoles: {
			'email:alice@example.com': 'First Party',
			'email:bob@example.com': 'first party',
		},
	})
	assert.equal(result.error, 'duplicate_role')
})

test('too many recipients are rejected before submission', () => {
	const result = buildRecipientRoleAssignments({
		recipients: [email('a@example.com'), email('b@example.com')],
		parties: parties.slice(0, 1),
	})
	assert.equal(result.error, 'too_many_recipients')
})

test('stale mappings are removed and reset when the template changes', () => {
	const result = buildRecipientRoleAssignments({
		recipients: [email('alice@example.com')],
		parties: [{ name: 'Buyer', uuid: 'buyer' }],
		recipientRoles: {
			'email:alice@example.com': 'Old Party',
			'email:removed@example.com': 'Buyer',
		},
	})
	assert.equal(result.error, null)
	assert.deepEqual(result.recipientRoles, { 'email:alice@example.com': 'Buyer' })
})

test('duplicate party definitions and missing parties are reported', () => {
	assert.equal(buildRecipientRoleAssignments({
		recipients: [email('a@example.com')],
		parties: [{ name: 'Buyer' }, { name: 'buyer' }],
	}).error, 'duplicate_party_definition')
	assert.equal(buildRecipientRoleAssignments({
		recipients: [email('a@example.com')],
		parties: [],
	}).error, 'no_parties')
})

test('direct uploads expose stable ordinal party names', () => {
	assert.equal(ordinalPartyName(1), 'First Party')
	assert.equal(ordinalPartyName(2), 'Second Party')
	assert.deepEqual(createDefaultParties(2).map(party => party.name), ['First Party', 'Second Party'])
})
