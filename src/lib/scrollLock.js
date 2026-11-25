let locks = 0
let saved = null

export function lockBodyScroll() {
  if (locks === 0) {
    const scrollY = window.scrollY
    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth
    saved = {
      scrollY,
      overflow: document.body.style.overflow,
      position: document.body.style.position,
      top: document.body.style.top,
      width: document.body.style.width,
      paddingRight: document.body.style.paddingRight,
    }

    document.body.style.overflow = 'hidden'
    document.body.style.position = 'fixed'
    document.body.style.top = `-${scrollY}px`
    document.body.style.width = '100%'

    if (scrollbarWidth > 0) {
      document.body.style.paddingRight = `${scrollbarWidth}px`
    }
  }

  locks += 1
}

export function unlockBodyScroll() {
  if (locks === 0) {
    return
  }

  locks -= 1

  if (locks > 0) {
    return
  }

  const state = saved
  saved = null
  document.body.style.overflow = state.overflow
  document.body.style.position = state.position
  document.body.style.top = state.top
  document.body.style.width = state.width
  document.body.style.paddingRight = state.paddingRight
  window.scrollTo(0, state.scrollY)
}
