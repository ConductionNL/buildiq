// SPDX-License-Identifier: EUPL-1.2
import { useRegisterPicker } from './useRegisterPicker.js'

/**
 * `setup()` of an editor that binds registers: the picker, following the app's own
 * register through a getter, exposed as `this.picker`.
 *
 * @param {object} props - the resolved component props.
 * @return {{picker: object}} the picker.
 * @spec openspec/specs/version-routing-ui/spec.md#requirement-version-composables-resolve-active-version-and-manifest-history
 */
export function useEditorRegisterPicker(props) {
	return {
		picker: useRegisterPicker({
			appSlug: props.appSlug,
			appRegister: () => props.appRegister,
			dataRegisters: props.dataRegisters,
		}),
	}
}
