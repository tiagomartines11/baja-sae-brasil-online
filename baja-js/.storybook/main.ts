const config = {
  stories: ['../src/components/**/*.stories.tsx'],
  addons: ['@storybook/addon-essentials', '@storybook/addon-viewport'],
  framework: {
    name: '@storybook/nextjs',
    options: {}
  },
  staticDirs: ['..\\public'],
  docs: {},
  webpackFinal: (config) => {
    config.resolve.modules.push(`${process.cwd()}/src`)
    return config
  }
}
export default config
