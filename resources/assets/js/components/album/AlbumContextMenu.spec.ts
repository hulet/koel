import Router from '@/router'
import { expect, it } from 'vitest'
import { screen } from '@testing-library/vue'
import UnitTestCase from '@/__tests__/UnitTestCase'
import factory from '@/__tests__/factory'
import { eventBus } from '@/utils/eventBus'
import { downloadService } from '@/services/downloadService'
import { playbackService } from '@/services/playbackService'
import { commonStore } from '@/stores/commonStore'
import { songStore } from '@/stores/songStore'
import AlbumContextMenu from './AlbumContextMenu.vue'
import { resourcePermissionService } from '@/services/resourcePermissionService'

let album: Album

new class extends UnitTestCase {
  protected test () {
    it('renders', async () => expect((await this.renderComponent()).html()).toMatchSnapshot())

    it('plays all', async () => {
      const songs = factory('song', 10)
      const fetchMock = this.mock(songStore, 'fetchForAlbum').mockResolvedValue(songs)
      const playMock = this.mock(playbackService, 'queueAndPlay')

      await this.renderComponent()
      await this.user.click(screen.getByText('Play All'))
      await this.tick()

      expect(fetchMock).toHaveBeenCalledWith(album)
      expect(playMock).toHaveBeenCalledWith(songs)
    })

    it('shuffles all', async () => {
      const songs = factory('song', 10)
      const fetchMock = this.mock(songStore, 'fetchForAlbum').mockResolvedValue(songs)
      const playMock = this.mock(playbackService, 'queueAndPlay')

      await this.renderComponent()
      await this.user.click(screen.getByText('Shuffle All'))
      await this.tick()

      expect(fetchMock).toHaveBeenCalledWith(album)
      expect(playMock).toHaveBeenCalledWith(songs, true)
    })

    it('downloads', async () => {
      const downloadMock = this.mock(downloadService, 'fromAlbum')
      await this.renderComponent()

      await this.user.click(screen.getByText('Download'))

      expect(downloadMock).toHaveBeenCalledWith(album)
    })

    it('does not have an option to download if downloading is disabled', async () => {
      commonStore.state.allows_download = false
      await this.renderComponent()

      expect(screen.queryByText('Download')).toBeNull()
    })

    it('goes to album', async () => {
      const mock = this.mock(Router, 'go')
      await this.renderComponent()

      await this.user.click(screen.getByText('Go to Album'))

      expect(mock).toHaveBeenCalledWith(`/#/albums/${album.id}`)
    })

    it('does not have an option to download or go to Unknown Album and Artist', async () => {
      await this.renderComponent(factory.states('unknown')('album'))

      expect(screen.queryByText('Go to Album')).toBeNull()
      expect(screen.queryByText('Go to Artist')).toBeNull()
      expect(screen.queryByText('Download')).toBeNull()
    })

    it('goes to artist', async () => {
      const mock = this.mock(Router, 'go')
      await this.renderComponent()

      await this.user.click(screen.getByText('Go to Artist'))

      expect(mock).toHaveBeenCalledWith(`/#/artists/${album.artist_id}`)
    })

    it('requests edit form', async () => {
      await this.renderComponent()

      // for the "Edit…" menu item to show up
      await this.tick(2)

      const emitMock = this.mock(eventBus, 'emit')
      await this.user.click(screen.getByText('Edit…'))

      expect(emitMock).toHaveBeenCalledWith('MODAL_SHOW_EDIT_ALBUM_FORM', album)
    })
  }

  private async renderComponent (_album?: Album) {
    this.mock(resourcePermissionService, 'check').mockReturnValue(true)

    album = _album || factory('album', {
      name: 'IV',
    })

    const rendered = this.beAdmin().render(AlbumContextMenu)
    eventBus.emit('ALBUM_CONTEXT_MENU_REQUESTED', { pageX: 420, pageY: 42 } as MouseEvent, album)
    await this.tick(2)

    return rendered
  }
}
