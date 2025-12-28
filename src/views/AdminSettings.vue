<template>
	<div class="files-labels-admin-settings">
		<h2>{{ t('files_labels', 'File Labels') }}</h2>

		<div class="files-labels-admin-settings__section">
			<h3>{{ t('files_labels', 'Rate Limiting') }}</h3>
			<p class="files-labels-admin-settings__description">
				{{ t('files_labels', 'Configure the maximum number of labels each user can create. This prevents abuse and ensures system stability.') }}
			</p>

			<div class="files-labels-admin-settings__field">
				<label for="max-labels-per-user">
					{{ t('files_labels', 'Maximum labels per user') }}
				</label>
				<input
					id="max-labels-per-user"
					v-model.number="maxLabelsPerUser"
					type="number"
					min="100"
					max="1000000"
					:disabled="saving"
					@change="saveSettings">
				<span v-if="saving" class="files-labels-admin-settings__status">
					{{ t('files_labels', 'Saving...') }}
				</span>
				<span v-else-if="saved" class="files-labels-admin-settings__status files-labels-admin-settings__status--success">
					{{ t('files_labels', 'Saved') }}
				</span>
				<span v-else-if="error" class="files-labels-admin-settings__status files-labels-admin-settings__status--error">
					{{ error }}
				</span>
			</div>

			<p class="files-labels-admin-settings__hint">
				{{ t('files_labels', 'Default: 10,000 labels. Minimum: 100. Maximum: 1,000,000.') }}
			</p>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'AdminSettings',

	data() {
		const initialState = loadState('files_labels', 'admin-settings', {
			maxLabelsPerUser: 10000,
		})

		return {
			maxLabelsPerUser: initialState.maxLabelsPerUser,
			saving: false,
			saved: false,
			error: null,
			saveTimeout: null,
		}
	},

	methods: {
		async saveSettings() {
			// Clear any existing timeout
			if (this.saveTimeout) {
				clearTimeout(this.saveTimeout)
			}

			this.saving = true
			this.saved = false
			this.error = null

			try {
				await axios.put(
					generateUrl('/apps/files_labels/admin/settings/max-labels'),
					{ value: this.maxLabelsPerUser }
				)
				this.saved = true
				// Clear saved status after 3 seconds
				this.saveTimeout = setTimeout(() => {
					this.saved = false
				}, 3000)
			} catch (err) {
				this.error = err.response?.data?.error || t('files_labels', 'Failed to save settings')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.files-labels-admin-settings {
	padding: 20px;
	max-width: 800px;

	h2 {
		margin-bottom: 20px;
	}

	h3 {
		margin-bottom: 10px;
	}

	&__section {
		margin-bottom: 30px;
	}

	&__description {
		color: var(--color-text-maxcontrast);
		margin-bottom: 15px;
	}

	&__field {
		display: flex;
		align-items: center;
		gap: 10px;
		margin-bottom: 10px;

		label {
			min-width: 180px;
		}

		input[type="number"] {
			width: 150px;
			padding: 8px;
		}
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__status {
		font-size: 0.9em;

		&--success {
			color: var(--color-success);
		}

		&--error {
			color: var(--color-error);
		}
	}
}
</style>
