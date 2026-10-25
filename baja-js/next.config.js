// import type { NextConfig } from 'next'

const nextConfig = {
  compiler: {
    styledComponents: {
      // Component names in class names only while developing, as Next 14
      // did by default; Turbopack would otherwise add them in production too.
      displayName: process.env.NODE_ENV !== 'production'
    }
  }
}

module.exports = nextConfig
