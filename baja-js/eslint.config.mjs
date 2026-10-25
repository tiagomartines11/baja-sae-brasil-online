import js from '@eslint/js'
import nextCoreWebVitals from 'eslint-config-next/core-web-vitals'
import nextTypescript from 'eslint-config-next/typescript'
import prettier from 'eslint-config-prettier'
import react from 'eslint-plugin-react'
import storybook from 'eslint-plugin-storybook'
import globals from 'globals'

// Same rule sets, in the same order, as the .eslintrc.json this replaces
// (Next 16 removed `next lint`, and with it the legacy config format).
const config = [
  {
    ignores: ['generators/', 'coverage/', 'storybook-static/']
  },
  ...nextCoreWebVitals,
  js.configs.recommended,
  ...nextTypescript,
  // eslint-config-next registers the react plugin already; take the rules.
  { rules: react.configs.flat.recommended.rules },
  prettier,
  ...storybook.configs['flat/recommended'],
  {
    languageOptions: {
      globals: globals.browser,
      ecmaVersion: 'latest',
      sourceType: 'module'
    },
    rules: {
      'react-hooks/rules-of-hooks': 'error',
      'react-hooks/exhaustive-deps': 'warn',
      'react/prop-types': 'off',
      'react/react-in-jsx-scope': 'off',
      '@typescript-eslint/explicit-module-boundary-types': 'off',
      // eslint-config-next/typescript lowers these to warnings; the
      // @typescript-eslint/recommended we extended before had them as errors.
      '@typescript-eslint/no-unused-vars': 'error',
      '@typescript-eslint/no-unused-expressions': 'error'
    }
  }
]

export default config
