<!--
  - SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="files-labels-tab">
		<div v-if="loading" class="loading-container">
			<div class="icon-loading" />
			<p>{{ t('files_labels', 'Loading labels...') }}</p>
		</div>

		<div v-else class="labels-content">
			<!-- Existing labels list -->
			<div v-if="Object.keys(labels).length > 0" class="labels-list">
				<h3>{{ t('files_labels', 'Current labels') }}</h3>
				<ul class="label-items">
					<li v-for="(value, key) in labels" :key="key" class="label-item">
						<div class="label-display">
							<span class="label-key">{{ key }}</span>
							<span class="label-separator">:</span>
							<span class="label-value">{{ value }}</span>
						</div>
						<NcActions>
							<NcActionButton
								:aria-label="t('files_labels', 'Delete label')"
								icon="icon-delete"
								@click="deleteLabel(key)">
								{{ t('files_labels', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</li>
				</ul>
			</div>

			<div v-else class="empty-content">
				<BookmarkOutline :size="64" class="empty-icon" />
				<p>{{ t('files_labels', 'No labels yet') }}</p>
			</div>

			<!-- Add new label form -->
			<div class="add-label-form">
				<h3>{{ t('files_labels', 'Add label') }}</h3>
				<form @submit.prevent="addLabel">
					<div class="form-row">
						<input
							v-model="newKey"
							type="text"
							:placeholder="t('files_labels', 'Key')"
							class="label-input"
							required
							maxlength="255"
							:disabled="saving">
					</div>
					<div class="form-row">
						<input
							v-model="newValue"
							type="text"
							:placeholder="t('files_labels', 'Value')"
							class="label-input"
							required
							maxlength="4000"
							:disabled="saving">
					</div>
					<div class="form-actions">
						<NcButton
							type="primary"
							native-type="submit"
							:disabled="saving || !newKey || !newValue">
							<template #icon>
								<span v-if="saving" class="icon-loading-small" />
								<span v-else class="icon-add" />
							</template>
							{{ saving ? t('files_labels', 'Saving...') : t('files_labels', 'Add label') }}
						</NcButton>
					</div>
				</form>
			</div>

			<!-- Error message -->
			<div v-if="error" class="error-message">
				<p>{{ error }}</p>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { emit } from '@nextcloud/event-bus'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import BookmarkOutline from 'vue-material-design-icons/BookmarkOutline.vue'

export default {
	name: 'LabelsSidebarTab',

	components: {
		NcButton,
		NcActions,
		NcActionButton,
		BookmarkOutline,
	},

	props: {
		fileInfo: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			labels: {},
			loading: false,
			saving: false,
			error: null,
			newKey: '',
			newValue: '',
		}
	},

	computed: {
		fileId() {
			return this.fileInfo?.id
		},
	},

	watch: {
		fileInfo: {
			immediate: true,
			handler() {
				this.loadLabels()
			},
		},
	},

	methods: {
		t,

		async loadLabels() {
			if (!this.fileId) {
				return
			}

			this.loading = true
			this.error = null

			try {
				const url = generateOcsUrl('apps/files_labels/api/v1/labels/{fileId}', {
					fileId: this.fileId,
				})
				const response = await axios.get(url)
				this.labels = response.data.ocs.data || {}
			} catch (error) {
				console.error('Failed to load labels:', error)
				this.error = t('files_labels', 'Failed to load labels')
				showError(t('files_labels', 'Failed to load labels'))
			} finally {
				this.loading = false
			}
		},

		async addLabel() {
			if (!this.newKey || !this.newValue || !this.fileId) {
				return
			}

			// Validate key doesn't already exist
			if (Object.prototype.hasOwnProperty.call(this.labels, this.newKey)) {
				showError(t('files_labels', 'A label with this key already exists'))
				return
			}

			this.saving = true
			this.error = null

			try {
				const url = generateOcsUrl('apps/files_labels/api/v1/labels/{fileId}/{key}', {
					fileId: this.fileId,
					key: this.newKey,
				})

				await axios.put(url, {
					value: this.newValue,
				})

				// Update local state
				this.labels = {
					...this.labels,
					[this.newKey]: this.newValue,
				}

				// Emit event for other apps (e.g., files_spoilers)
				emit('files_labels:label-changed', {
					fileId: this.fileId,
					labels: { ...this.labels },
				})

				// Clear form
				this.newKey = ''
				this.newValue = ''

				showSuccess(t('files_labels', 'Label added successfully'))
			} catch (error) {
				console.error('Failed to add label:', error)
				const message = error.response?.data?.ocs?.meta?.message
					|| t('files_labels', 'Failed to add label')
				this.error = message
				showError(message)
			} finally {
				this.saving = false
			}
		},

		async deleteLabel(key) {
			if (!this.fileId) {
				return
			}

			try {
				const url = generateOcsUrl('apps/files_labels/api/v1/labels/{fileId}/{key}', {
					fileId: this.fileId,
					key,
				})

				await axios.delete(url)

				// Update local state
				const newLabels = { ...this.labels }
				delete newLabels[key]
				this.labels = newLabels

				// Emit event for other apps (e.g., files_spoilers)
				emit('files_labels:label-changed', {
					fileId: this.fileId,
					labels: { ...this.labels },
				})

				showSuccess(t('files_labels', 'Label deleted successfully'))
			} catch (error) {
				console.error('Failed to delete label:', error)
				const message = error.response?.data?.ocs?.meta?.message
					|| t('files_labels', 'Failed to delete label')
				showError(message)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.files-labels-tab {
	padding: 20px;
	min-height: 300px;
}

.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px 20px;

	.icon-loading {
		margin-bottom: 12px;
	}

	p {
		color: var(--color-text-lighter);
	}
}

.labels-content {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.labels-list {
	h3 {
		margin: 0 0 12px 0;
		font-size: 16px;
		font-weight: 600;
		color: var(--color-main-text);
	}
}

.label-items {
	list-style: none;
	padding: 0;
	margin: 0;
}

.label-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	margin-bottom: 4px;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
	transition: background-color 0.2s ease;

	&:hover {
		background-color: var(--color-background-hover);
	}
}

.label-display {
	flex: 1;
	display: flex;
	align-items: baseline;
	gap: 8px;
	min-width: 0;
	word-break: break-word;
}

.label-key {
	font-weight: 600;
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.label-separator {
	color: var(--color-text-lighter);
	flex-shrink: 0;
}

.label-value {
	color: var(--color-main-text);
	word-break: break-word;
}

.empty-content {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px 20px;
	color: var(--color-text-lighter);

	.empty-icon {
		opacity: 0.3;
		margin-bottom: 12px;
	}

	p {
		margin: 0;
	}
}

.add-label-form {
	border-top: 1px solid var(--color-border);
	padding-top: 16px;

	h3 {
		margin: 0 0 12px 0;
		font-size: 16px;
		font-weight: 600;
		color: var(--color-main-text);
	}

	form {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
}

.form-row {
	display: flex;
	flex-direction: column;
}

.label-input {
	width: 100%;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;

	&:focus {
		outline: none;
		border-color: var(--color-primary-element);
	}

	&:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}

	&::placeholder {
		color: var(--color-text-lighter);
	}
}

.form-actions {
	margin-top: 8px;
	display: flex;
	justify-content: flex-end;
}

.error-message {
	padding: 12px;
	background-color: var(--color-error);
	color: var(--color-primary-text);
	border-radius: var(--border-radius);

	p {
		margin: 0;
		font-size: 14px;
	}
}
</style>
