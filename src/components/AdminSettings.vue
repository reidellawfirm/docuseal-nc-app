<template>
	<div id="docuseal-admin-settings" class="section">
		<h2>
			<DocuSealIcon :size="24" class="icon" />
			DocuSeal Integration
		</h2>
		<p class="settings-hint">
			{{ t('docuseal_nc_app', 'Configura la connessione al tuo server DocuSeal Enterprise per abilitare la firma elettronica dei documenti.') }}
		</p>

		<div class="field">
			<label for="docuseal-server-url">
				{{ t('docuseal_nc_app', 'URL del server DocuSeal') }}
			</label>
			<input
				id="docuseal-server-url"
				v-model="state.server_url"
				type="url"
				:placeholder="t('docuseal_nc_app', 'https://docuseal.example.com')"
				@input="onInput">
		</div>

		<div class="field">
			<label for="docuseal-api-key">
				{{ t('docuseal_nc_app', 'API Key') }}
			</label>
			<input
				id="docuseal-api-key"
				v-model="apiKey"
				type="password"
				:placeholder="state.api_key_set ? t('docuseal_nc_app', '● ● ● ● ● (chiave già configurata)') : t('docuseal_nc_app', 'Inserisci la API Key')"
				@input="onInput">
			<p class="hint">
				{{ t('docuseal_nc_app', 'Puoi trovare la API Key nelle impostazioni del tuo server DocuSeal: Impostazioni → API') }}
			</p>
		</div>

		<div class="field">
			<label for="docuseal-user-email">
				{{ t('docuseal_nc_app', 'DocuSeal account email') }}
			</label>
			<input
				id="docuseal-user-email"
				v-model="state.docuseal_user_email"
				type="email"
				placeholder="admin@example.com"
				autocomplete="email"
				@input="onInput">
			<p class="hint">
				{{ t('docuseal_nc_app', 'Email of the DocuSeal account that owns the API key. Required to configure signature fields.') }}
			</p>
		</div>

		<div class="field">
			<label for="docuseal-webhook-secret">
				{{ t('docuseal_nc_app', 'Webhook Secret (opzionale)') }}
			</label>
			<input
				id="docuseal-webhook-secret"
				v-model="webhookSecret"
				type="password"
				:placeholder="state.webhook_secret_set ? t('docuseal_nc_app', '● ● ● ● ● (secret già configurato)') : t('docuseal_nc_app', 'Inserisci un secret per validare i webhook')"
				@input="onInput">
			<p class="hint">
				{{ t('docuseal_nc_app', 'Se configurato, i webhook verranno validati con HMAC-SHA256. Usa lo stesso secret nella configurazione webhook di DocuSeal.') }}
			</p>
		</div>

		<div class="field">
			<label>
				{{ t('docuseal_nc_app', 'URL Webhook (configura in DocuSeal)') }}
			</label>
			<div class="webhook-url-container">
				<input
					:value="webhookUrl"
					type="text"
					readonly
					class="webhook-url"
					@click="copyWebhookUrl">
				<NcButton type="tertiary" @click="copyWebhookUrl">
					{{ t('docuseal_nc_app', 'Copia') }}
				</NcButton>
			</div>
			<p class="hint">
				{{ t('docuseal_nc_app', 'Copia questo URL e configuralo nelle impostazioni webhook di DocuSeal: Impostazioni → Webhook') }}
			</p>
		</div>

		<div class="actions">
			<NcButton
				type="primary"
				:disabled="saving"
				@click="saveConfig">
				{{ saving ? t('docuseal_nc_app', 'Salvataggio...') : t('docuseal_nc_app', 'Salva') }}
			</NcButton>
			<NcButton
				v-if="state.api_key_set"
				type="error"
				@click="resetConfig">
				{{ t('docuseal_nc_app', 'Disconnetti') }}
			</NcButton>
		</div>

		<div v-if="connectionStatus !== null" class="connection-status" :class="connectionStatus.success ? 'success' : 'error'">
			<span v-if="connectionStatus.success">&#10003; {{ t('docuseal_nc_app', 'Connessione riuscita al server DocuSeal') }}</span>
			<span v-else>&#10007; {{ t('docuseal_nc_app', 'Errore di connessione:') }} {{ connectionStatus.message }}</span>
		</div>

		<!-- Supported file types info -->
		<div class="info-section">
			<h3>{{ t('docuseal_nc_app', 'Tipi di file supportati') }}</h3>
			<ul>
				<li>PDF (.pdf)</li>
				<li>Microsoft Word (.docx, .doc)</li>
				<li>{{ t('docuseal_nc_app', 'Immagini') }} (.png, .jpg, .jpeg)</li>
			</ul>
		</div>

		<div class="info-section">
			<h3>{{ t('docuseal_nc_app', 'Funzionalità') }}</h3>
			<ul>
				<li>{{ t('docuseal_nc_app', 'Invio diretto file per la firma') }}</li>
				<li>{{ t('docuseal_nc_app', 'Firma tramite template DocuSeal') }}</li>
				<li>{{ t('docuseal_nc_app', 'Firma embedded (in-app) o via email') }}</li>
				<li>{{ t('docuseal_nc_app', 'Tracking stato firme nella sidebar dei file') }}</li>
				<li>{{ t('docuseal_nc_app', 'Download automatico documenti firmati') }}</li>
				<li>{{ t('docuseal_nc_app', 'Notifiche in tempo reale') }}</li>
				<li>{{ t('docuseal_nc_app', 'Ricerca firme dalla barra di ricerca') }}</li>
				<li>{{ t('docuseal_nc_app', 'Widget Dashboard') }}</li>
			</ul>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '../utils/translate'
import { loadState } from '@nextcloud/initial-state'
import { showSuccess, showError } from '@nextcloud/dialogs'
import DocuSealIcon from '../icons/DocuSealIcon.vue'

export default {
	name: 'AdminSettings',

	components: {
		NcButton,
		DocuSealIcon,
	},

	data() {
		return {
			state: loadState('docuseal_nc_app', 'docuseal-config'),
			apiKey: '',
			webhookSecret: '',
			saving: false,
			connectionStatus: null,
		}
	},

	computed: {
		webhookUrl() {
			return window.location.protocol + '//' + window.location.host
				+ generateUrl('/apps/docuseal_nc_app/webhook')
		},
	},

	methods: {
		t,
		onInput() {
			this.connectionStatus = null
		},

		async saveConfig() {
			this.saving = true
			try {
				const params = {
					server_url: this.state.server_url,
					docuseal_user_email: this.state.docuseal_user_email || '',
				}
				if (this.apiKey !== '') {
					params.api_key = this.apiKey
				}
				if (this.webhookSecret !== '') {
					params.webhook_secret = this.webhookSecret
				}
				const url = generateUrl('/apps/docuseal_nc_app/config')
				const response = await axios.put(url, params)

				this.state = response.data
				this.apiKey = ''
				this.webhookSecret = ''

				if (response.data.connection_test) {
					this.connectionStatus = response.data.connection_test
					if (response.data.connection_test.success) {
						showSuccess(t('docuseal_nc_app', 'Configurazione salvata e connessione verificata'))
					} else {
						showError(t('docuseal_nc_app', 'Configurazione salvata ma la connessione ha fallito: ') + response.data.connection_test.message)
					}
				} else {
					showSuccess(t('docuseal_nc_app', 'Configurazione salvata'))
				}
			} catch (e) {
				const serverError = e.response?.data?.error
				showError(typeof serverError === 'string' && serverError !== ''
					? serverError
					: t('docuseal_nc_app', 'Errore nel salvataggio della configurazione'))
				console.error(e)
			}
			this.saving = false
		},

		async resetConfig() {
			if (!confirm(t('docuseal_nc_app', 'Sei sicuro di voler disconnettere DocuSeal?'))) {
				return
			}
			try {
				const url = generateUrl('/apps/docuseal_nc_app/config')
				await axios.delete(url)
				this.state = { server_url: '', api_key_set: false, webhook_secret_set: false, docuseal_user_email: '' }
				this.connectionStatus = null
				showSuccess(t('docuseal_nc_app', 'DocuSeal disconnesso'))
			} catch (e) {
				showError(t('docuseal_nc_app', 'Errore nella disconnessione'))
			}
		},

		copyWebhookUrl() {
			navigator.clipboard.writeText(this.webhookUrl).then(() => {
				showSuccess(t('docuseal_nc_app', 'URL webhook copiato negli appunti'))
			})
		},
	},
}
</script>

<style scoped>
#docuseal-admin-settings {
	padding: 20px;
}

h2 {
	display: flex;
	align-items: center;
	gap: 8px;
}

.icon {
	display: inline-flex;
}

.settings-hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.field {
	margin-bottom: 16px;
}

.field label {
	display: block;
	font-weight: bold;
	margin-bottom: 4px;
}

.field input {
	width: 100%;
	max-width: 400px;
}

.field .hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.webhook-url-container {
	display: flex;
	gap: 8px;
	align-items: center;
}

.webhook-url {
	cursor: pointer;
	background-color: var(--color-background-dark) !important;
	font-family: monospace;
	font-size: 0.85em;
	flex: 1;
}

.actions {
	display: flex;
	gap: 8px;
	margin-top: 20px;
	margin-bottom: 20px;
}

.connection-status {
	margin-top: 16px;
	margin-bottom: 16px;
	padding: 10px 16px;
	border-radius: var(--border-radius);
}

.connection-status.success {
	background-color: var(--color-success);
	color: white;
}

.connection-status.error {
	background-color: var(--color-error);
	color: white;
}

.info-section {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.info-section h3 {
	margin-bottom: 8px;
}

.info-section ul {
	padding-left: 20px;
	color: var(--color-text-maxcontrast);
}

.info-section li {
	margin-bottom: 4px;
}
</style>
