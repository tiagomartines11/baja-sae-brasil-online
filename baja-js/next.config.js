// import type { NextConfig } from 'next'

const nextConfig = {
  compiler: {
    styledComponents: {
      // Component names in class names only while developing, as Next 14
      // did by default; Turbopack would otherwise add them in production too.
      displayName: process.env.NODE_ENV !== 'production'
    }
  },
  // The site never uses next/image, so the optimizer at /_next/image only
  // adds attack surface (it has had several remote code execution and DoS
  // advisories). With this set, Next does not serve it.
  images: {
    unoptimized: true
  }
}

module.exports = nextConfig
