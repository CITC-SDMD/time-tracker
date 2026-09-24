// @ts-check
import stylistic from '@stylistic/eslint-plugin'
import tseslint from 'typescript-eslint'

// No Nuxt here (plain TS package), so this hand-rolls the same style the
// @nuxt/eslint stylistic preset applies in apps/agent and apps/dashboard:
// single quotes, no semicolons, 2-space indent.
export default tseslint.config(
  { ignores: ['**/*.js'] },
  tseslint.configs.recommended,
  {
    plugins: { '@stylistic': stylistic },
    rules: {
      '@stylistic/semi': ['error', 'never'],
      '@stylistic/quotes': ['error', 'single'],
      '@stylistic/indent': ['error', 2],
    },
  },
)
