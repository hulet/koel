import { useAuthorization } from '@/composables/useAuthorization'
import { get as baseGet, remove as baseRemove, set as baseSet } from 'local-storage'

export const useLocalStorage = (namespaced = true, user?: User) => {
  let namespace = ''

  if (namespaced) {
    namespace = user ? `${user.id}::` : `${useAuthorization().currentUser.value.id}::`
  }

  const get = <T> (key: string, defaultValue: T | null = null): T | null => {
    const value = baseGet<T>(namespace + key)

    return value === null ? defaultValue : value
  }

  const set = (key: string, value: any) => baseSet(namespace + key, value)
  const remove = (key: string) => baseRemove(namespace + key)

  return {
    get,
    set,
    remove,
  }
}
