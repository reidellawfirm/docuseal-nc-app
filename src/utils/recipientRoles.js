export function recipientRoleKey(recipient) {
	if (recipient?.type === 'user' && recipient.id) {
		return `user:${recipient.id}`
	}
	return `email:${String(recipient?.email || '').trim().toLowerCase()}`
}

export function ordinalPartyName(number) {
	const names = [
		'First', 'Second', 'Third', 'Fourth', 'Fifth',
		'Sixth', 'Seventh', 'Eighth', 'Ninth', 'Tenth',
	]
	return `${names[number - 1] || `${number}th`} Party`
}

export function createDefaultParties(count) {
	return Array.from({ length: Math.max(1, count) }, (_, index) => ({
		name: ordinalPartyName(index + 1),
		uuid: `local-party-${index + 1}`,
	}))
}

export function getCurrentUserId() {
	const ocUser = globalThis.OC?.currentUser
	if (typeof ocUser === 'string' && ocUser) return ocUser
	if (ocUser?.uid) return ocUser.uid

	// Nextcloud exposes the authenticated user on the document head. This is
	// the same source used by @nextcloud/auth and works without a global OC helper.
	const head = globalThis.document?.getElementsByTagName('head')?.[0]
	return head?.getAttribute('data-user') || null
}

/**
 * Preserve explicit recipient-to-party choices and fill missing choices with
 * the first unused template party. Self-signing reserves at most one party,
 * even when the current user was already selected in the recipient list.
 */
export function buildRecipientRoleAssignments({
recipients = [],
parties = [],
recipientRoles = {},
currentUserRole = '',
currentUserId = null,
embedSign = false,
}) {
const partyNames = parties.map(party => String(party?.name || '').trim()).filter(Boolean)
const partyLookup = new Map(partyNames.map(name => [name.toLowerCase(), name]))
const selfKey = currentUserId ? `user:${currentUserId}` : null
const selfInRecipients = Boolean(embedSign && selfKey
&& recipients.some(recipient => recipientRoleKey(recipient) === selfKey))
const recipientKeys = recipients.map(recipientRoleKey)
const uniqueRecipientKeys = new Set(recipientKeys)
const selfNeedsParty = Boolean(embedSign && !selfInRecipients)
const totalRecipients = recipients.length + (selfNeedsParty ? 1 : 0)

// Keep only mappings for current recipients and parties that still exist.
// A template change should reset stale selections, not block the form.
const roles = Object.fromEntries(Object.entries(recipientRoles)
.filter(([key, role]) => uniqueRecipientKeys.has(key)
&& partyLookup.has(String(role || '').trim().toLowerCase())))
let selfRole = partyLookup.has(String(currentUserRole || '').trim().toLowerCase())
? partyLookup.get(String(currentUserRole).trim().toLowerCase())
: ''

let error = null
if (uniqueRecipientKeys.size !== recipients.length) error = 'duplicate_recipient'
if (new Set(partyNames.map(name => name.toLowerCase())).size !== partyNames.length) {
error ||= 'duplicate_party_definition'
}
if (partyNames.length === 0 && totalRecipients > 0) {
return { recipientRoles: roles, currentUserRole: selfRole, selfInRecipients, error: error || 'no_parties' }
}
if (totalRecipients > partyNames.length) {
return { recipientRoles: roles, currentUserRole: selfRole, selfInRecipients, error: error || 'too_many_recipients' }
}

const entries = []
if (selfNeedsParty) {
entries.push({ key: selfKey || '__current_user__', self: true, explicitRole: selfRole })
}
for (let index = 0; index < recipients.length; index++) {
const key = recipientKeys[index]
const isSelf = selfInRecipients && key === selfKey
entries.push({
key,
self: isSelf,
explicitRole: isSelf ? (selfRole || roles[key] || '') : (roles[key] || ''),
})
}

const used = new Set()
const reserve = (role) => {
const canonical = partyLookup.get(String(role || '').trim().toLowerCase())
if (!canonical) return ''
const key = canonical.toLowerCase()
if (used.has(key)) {
error ||= 'duplicate_role'
return canonical
}
used.add(key)
return canonical
}

// Reserve explicit choices first so automatic fallbacks cannot steal them.
for (const entry of entries) entry.role = entry.explicitRole ? reserve(entry.explicitRole) : ''
for (const entry of entries) {
if (!entry.role) {
entry.role = reserve(partyNames.find(name => !used.has(name.toLowerCase())) || '')
}
if (entry.self) {
selfRole = entry.role
if (selfInRecipients && selfKey) roles[selfKey] = entry.role
} else if (uniqueRecipientKeys.has(entry.key)) {
roles[entry.key] = entry.role
}
}

return {
recipientRoles: roles,
currentUserRole: embedSign ? selfRole : '',
selfInRecipients,
error,
}
}
